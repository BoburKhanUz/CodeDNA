<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\Assessment\AssessmentFailure;
use App\Services\Analyzer\JsonSchemaValidator;
use JsonException;
use stdClass;

/**
 * Validates a provider's raw response against the assessment contract
 * (docs/architecture/ai-assessment-v1.md#validation). Every step must pass;
 * there is no repair and no fallback:
 *
 *  1. size: at most the configured number of bytes;
 *  2. extraction: exactly one JSON object (optionally in one ```json fence);
 *  3. forbidden fields (score, confidence, priority, ...) at any depth;
 *  4. the assessment/v1 schema (closed objects, lengths, reference format);
 *  5. item caps;
 *  6. evidence references: every one must name an item of this input;
 *  7. content rules: no scores, seniority, person judgments, links or
 *     courses, history claims, prompt leaks, claims about unmeasured topics
 *     outside limitations, numbers that are not in the evidence, or
 *     strengths built on a material gap or unassessed competency.
 *
 * Failures carry a fixed rule identifier only, never the offending text.
 */
final class AssessmentResponseValidator
{
    private const SECTIONS = ['strengths', 'areas_to_improve', 'development_insights', 'limitations'];

    public function __construct(private readonly JsonSchemaValidator $schema) {}

    /**
     * @return array<string, mixed> the validated output, in a fixed key order
     *
     * @throws AssessmentException OUTPUT_TOO_LARGE or INVALID_OUTPUT
     */
    public function validate(string $raw, AssessmentInput $input, AssessmentSpecification $spec, int $maxBytes): array
    {
        if (strlen($raw) > $maxBytes) {
            throw new AssessmentException(AssessmentFailure::OutputTooLarge, 'size');
        }
        $decoded = $this->extract($raw);

        $this->forbiddenKeys($decoded);
        if ($this->schema->validate($decoded, $spec->outputSchema()) !== []) {
            $this->fail('schema');
        }
        /** @var array<string, mixed> $output */
        $output = json_decode((string) json_encode($decoded, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);

        $this->limits($output);
        $this->references($output, $input);
        $this->content($output, $input);

        return $this->normalize($output);
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
                if (in_array(strtolower((string) $key), AssessmentSpecification::FORBIDDEN_KEYS, true)) {
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
     * @param  array<string, mixed>  $output
     */
    private function limits(array $output): void
    {
        foreach (self::SECTIONS as $section) {
            if (count($output[$section]) > AssessmentSpecification::LIMITS[$section.'_max_items']) {
                $this->fail('limits');
            }
        }
        foreach ($this->claims($output) as [, $claim]) {
            if (count($claim['evidence_refs']) > AssessmentSpecification::LIMITS['evidence_refs_max_items']) {
                $this->fail('limits');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function references(array $output, AssessmentInput $input): void
    {
        $known = array_flip($input->evidenceIds());
        foreach ($this->claims($output) as [, $claim]) {
            foreach ($claim['evidence_refs'] as $ref) {
                if (! isset($known[$ref])) {
                    $this->fail('evidence_ref_unknown');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function content(array $output, AssessmentInput $input): void
    {
        $numbers = $input->numbers();
        foreach ($this->claims($output) as [$section, $claim]) {
            foreach (['text', 'title', 'description'] as $field) {
                if (! isset($claim[$field])) {
                    continue;
                }
                $text = $claim[$field];
                if (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $text) === 1) {
                    $this->fail('control_characters');
                }
                foreach (AssessmentSpecification::FORBIDDEN_TEXT as $rule => $pattern) {
                    if (preg_match($pattern, $text) === 1) {
                        $this->fail($rule);
                    }
                }
                if ($section !== 'limitations' && preg_match(AssessmentSpecification::UNSUPPORTED_TOPICS, $text) === 1) {
                    $this->fail('unsupported_claim');
                }
                preg_match_all('/\d+(?:\.\d+)?/', $text, $found);
                foreach ($found[0] as $number) {
                    if (! isset($numbers[AssessmentInput::normalizeNumber($number)])) {
                        $this->fail('unsupported_number');
                    }
                }
            }
            if ($section === 'strengths') {
                $this->strength($claim, $input);
            }
        }
    }

    /**
     * A strength may not rest on a material gap or on a competency that
     * was not assessed.
     *
     * @param  array<string, mixed>  $claim
     */
    private function strength(array $claim, AssessmentInput $input): void
    {
        foreach ($claim['evidence_refs'] as $ref) {
            $status = $input->evidenceItem($ref)['facts']['status'] ?? null;
            $contradicts = match (strstr($ref, ':', true)) {
                'gap' => $status === 'GAP',
                'competency' => $status !== 'ASSESSED',
                default => false,
            };
            if ($contradicts) {
                $this->fail('contradicts_evidence');
            }
        }
    }

    /**
     * Every claim with its section: the summary first, then each section.
     *
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
            'strengths' => array_map($claim, $output['strengths']),
            'areas_to_improve' => array_map($claim, $output['areas_to_improve']),
            'development_insights' => array_map($claim, $output['development_insights']),
            'limitations' => array_map(fn (array $l): array => ['description' => $l['description'], 'evidence_refs' => $l['evidence_refs']], $output['limitations']),
        ];
    }

    private function fail(string $rule): never
    {
        throw new AssessmentException(AssessmentFailure::InvalidOutput, $rule);
    }
}
