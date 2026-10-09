<?php

declare(strict_types=1);

namespace App\Services\Insights;

use App\Enums\Insights\InsightFailure;
use App\Enums\Insights\InsightKind;
use App\Services\Analyzer\JsonSchemaValidator;
use JsonException;
use stdClass;

/**
 * Validates a model's raw answer against the insight contract (Phase 29,
 * docs/architecture/ai-intelligence-v1.md#validation). Every step must pass;
 * there is no repair, no retry of invalid output and no fallback text:
 *
 *  1. size, then exactly one JSON object (optionally in one ```json fence);
 *  2. forbidden fields at any depth (score, level, verdict, ...);
 *  3. the insight/v1 schema (closed objects, lengths, reference format, caps);
 *  4. evidence references: each must be the id of an item of this input;
 *  5. content rules on every text: no scores, seniority, person judgments,
 *     links, courses, markup or code, no prompt leaks, no control
 *     characters, and no number that is not a fact of the input;
 *  6. kind rules, which keep the text consistent with the deterministic data:
 *     - growth: in the summary and points (statements of fact), a claim of
 *       improvement or regression must cite observations with exactly that
 *       status (negated phrases such as "did not improve" excepted);
 *       unmeasured topics only in limitations;
 *     - roadmap: a next step must cite an AVAILABLE step, and no claim
 *       of change over time anywhere;
 *     - challenge: in the summary and points, "passed" only with PASSED
 *       results, "failed" only with FAILED / ERROR / NOT_RUN ones; no claim
 *       of change over time anywhere.
 *
 * Failures carry a fixed rule identifier only, never the offending text.
 */
final readonly class InsightResponseValidator
{
    private const SECTIONS = ['points', 'next_steps', 'limitations'];

    private const PASSED_STATUSES = ['PASSED'];

    private const FAILED_STATUSES = ['FAILED', 'ERROR', 'NOT_RUN'];

    public function __construct(private JsonSchemaValidator $schema) {}

    /**
     * @return array<string, mixed> the validated output, in a fixed key order
     *
     * @throws InsightException OUTPUT_TOO_LARGE or INVALID_OUTPUT
     */
    public function validate(string $raw, InsightInput $input, InsightSpecification $spec, int $maxBytes): array
    {
        if (strlen($raw) > $maxBytes) {
            throw new InsightException(InsightFailure::OutputTooLarge, 'size');
        }
        $decoded = $this->extract($raw);
        $this->forbiddenKeys($decoded);
        if ($this->schema->validate($decoded, $spec->outputSchema()) !== []) {
            $this->fail('schema');
        }
        /** @var array<string, mixed> $output */
        $output = json_decode((string) json_encode($decoded, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);

        $this->limits($output);
        $known = array_flip($input->evidenceIds());
        $numbers = $input->numbers();
        $names = self::identifierNames($input);
        foreach ($this->claims($output) as [$section, $claim]) {
            foreach ($claim['evidence_refs'] as $ref) {
                if (! isset($known[$ref])) {
                    $this->fail('evidence_ref_unknown');
                }
            }
            foreach (['text', 'title', 'description'] as $field) {
                if (isset($claim[$field])) {
                    $this->text($claim[$field], $numbers, $names, $input->kind, $section);
                }
            }
            $this->kindRules($section, $claim, $input);
        }

        return $this->normalize($output);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function limits(array $output): void
    {
        foreach (self::SECTIONS as $section) {
            if (count($output[$section]) > InsightSpecification::LIMITS[$section.'_max_items']) {
                $this->fail('limits');
            }
        }
        foreach ($this->claims($output) as [, $claim]) {
            if (count($claim['evidence_refs']) > InsightSpecification::LIMITS['evidence_refs_max_items']) {
                $this->fail('limits');
            }
        }
    }

    private function extract(string $raw): stdClass
    {
        $text = trim($raw);
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $text, $fence) === 1) {
            $text = trim($fence[1]);
        }
        if (! str_starts_with($text, '{') || ! mb_check_encoding($text, 'UTF-8')) {
            $this->fail('json');
        }
        try {
            $decoded = json_decode($text, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail('json');
        }
        if (! $decoded instanceof stdClass) {
            $this->fail('json');
        }

        return $decoded;
    }

    private function forbiddenKeys(mixed $value): void
    {
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $member) {
                if (in_array(strtolower((string) $key), InsightSpecification::FORBIDDEN_KEYS, true)) {
                    $this->fail('forbidden_field');
                }
                $this->forbiddenKeys($member);
            }
        } elseif (is_array($value)) {
            foreach ($value as $member) {
                $this->forbiddenKeys($member);
            }
        }
    }

    /**
     * The names inside evidence ids ("AC2", "h1", "fd-01", "FUNCTION_DESIGN"),
     * longest first: a claim may name them without their digits counting as
     * numbers.
     *
     * @return list<string>
     */
    private static function identifierNames(InsightInput $input): array
    {
        $names = [];
        foreach ($input->evidenceIds() as $id) {
            $name = substr($id, (int) strrpos($id, ':') + 1);
            if (preg_match('/\d/', $name) === 1) {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        usort($names, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $names;
    }

    /**
     * @param  array<string, true>  $numbers
     * @param  list<string>  $names
     */
    private function text(string $text, array $numbers, array $names, InsightKind $kind, string $section): void
    {
        if (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $text) === 1) {
            $this->fail('control_characters');
        }
        foreach (InsightSpecification::FORBIDDEN_TEXT as $rule => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $this->fail($rule);
            }
        }
        if ($kind !== InsightKind::GrowthInterpretation && preg_match(InsightSpecification::HISTORY_CLAIM, $text) === 1) {
            $this->fail('history_claim');
        }
        if ($kind !== InsightKind::ChallengeFeedback && $section !== 'limitations' && preg_match(InsightSpecification::UNSUPPORTED_TOPICS, $text) === 1) {
            $this->fail('unsupported_claim');
        }
        $scanned = $text;
        foreach ($names as $name) {
            $scanned = (string) preg_replace('/(?<![A-Za-z0-9_-])'.preg_quote($name, '/').'(?![A-Za-z0-9_-])/i', ' ', $scanned);
        }
        preg_match_all('/\d+(?:\.\d+)?/', $scanned, $found);
        foreach ($found[0] as $number) {
            if (! isset($numbers[InsightInput::normalizeNumber($number)])) {
                $this->fail('unsupported_number');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function kindRules(string $section, array $claim, InsightInput $input): void
    {
        $text = ($claim['title'] ?? '').' '.($claim['text'] ?? '').' '.($claim['description'] ?? '');
        // Statements of fact (summary and points) must match the measured
        // statuses; next steps are advice ("so that the tests pass") and
        // limitations say what cannot be concluded.
        $factual = in_array($section, ['summary', 'points'], true);
        match ($input->kind) {
            InsightKind::GrowthInterpretation => $factual ? $this->direction($text, $claim['evidence_refs'], $input) : null,
            InsightKind::RoadmapGuidance => $section === 'next_steps' ? $this->nextStep($claim['evidence_refs'], $input) : null,
            InsightKind::ChallengeFeedback => $factual ? $this->outcome($text, $claim['evidence_refs'], $input) : null,
        };
    }

    /**
     * A claim of improvement or regression must rest on observations with
     * exactly that measured status.
     *
     * @param  list<string>  $refs
     */
    private function direction(string $text, array $refs, InsightInput $input): void
    {
        $allowed = [];
        if ($this->affirms($text, InsightSpecification::IMPROVEMENT_WORDS)) {
            $allowed[] = 'IMPROVED';
        }
        if ($this->affirms($text, InsightSpecification::REGRESSION_WORDS)) {
            $allowed[] = 'REGRESSED';
        }
        if ($allowed === []) {
            return;
        }
        $observations = array_values(array_filter($refs, fn (string $ref): bool => str_starts_with($ref, 'obs:')));
        if ($observations === []) {
            $this->fail('direction_without_observation');
        }
        foreach ($observations as $ref) {
            if (! in_array($input->item($ref)['facts']['status'] ?? null, $allowed, true)) {
                $this->fail('direction_contradicts_evidence');
            }
        }
    }

    /**
     * A recommended next step must exist and be AVAILABLE.
     *
     * @param  list<string>  $refs
     */
    private function nextStep(array $refs, InsightInput $input): void
    {
        $steps = array_values(array_filter($refs, fn (string $ref): bool => str_starts_with($ref, 'step:')));
        if ($steps === []) {
            $this->fail('next_step_without_step');
        }
        foreach ($steps as $ref) {
            if (($input->item($ref)['facts']['state'] ?? null) !== 'AVAILABLE') {
                $this->fail('step_not_available');
            }
        }
    }

    /**
     * "Passed" only with PASSED results, "failed" only with failed ones.
     *
     * @param  list<string>  $refs
     */
    private function outcome(string $text, array $refs, InsightInput $input): void
    {
        $allowed = [];
        if ($this->affirms($text, InsightSpecification::PASS_WORDS)) {
            $allowed = [...$allowed, ...self::PASSED_STATUSES];
        }
        if ($this->affirms($text, InsightSpecification::FAIL_WORDS)) {
            $allowed = [...$allowed, ...self::FAILED_STATUSES];
        }
        if ($allowed === []) {
            return;
        }
        foreach ($refs as $ref) {
            $facts = $input->item($ref)['facts'] ?? [];
            $status = $facts['verdict'] ?? $facts['status'] ?? null;
            if ($status !== null && ! in_array($status, $allowed, true)) {
                $this->fail('outcome_contradicts_evidence');
            }
        }
    }

    /** Whether $pattern occurs at least once without a negation just before it. */
    private function affirms(string $text, string $pattern): bool
    {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return false;
        }
        foreach ($matches[0] as [, $offset]) {
            $before = substr($text, max(0, $offset - 40), min(40, $offset));
            if (preg_match(InsightSpecification::NEGATION, $before) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function claims(array $output): array
    {
        $claims = [['summary', $output['summary']]];
        foreach (self::SECTIONS as $section) {
            foreach ($output[$section] as $claim) {
                $claims[] = [$section, $claim];
            }
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function normalize(array $output): array
    {
        $claim = fn (array $c): array => ['title' => $c['title'], 'description' => $c['description'], 'evidence_refs' => $c['evidence_refs']];

        return [
            'schema_version' => $output['schema_version'],
            'summary' => ['text' => $output['summary']['text'], 'evidence_refs' => $output['summary']['evidence_refs']],
            'points' => array_map($claim, $output['points']),
            'next_steps' => array_map($claim, $output['next_steps']),
            'limitations' => array_map(fn (array $l): array => ['description' => $l['description'], 'evidence_refs' => $l['evidence_refs']], $output['limitations']),
        ];
    }

    private function fail(string $rule): never
    {
        throw new InsightException(InsightFailure::InvalidOutput, $rule);
    }
}
