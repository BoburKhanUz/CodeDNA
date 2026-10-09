<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\Assessment\AssessmentFailure;
use App\Services\Assessment\Provider\AiProviderException;
use App\Services\Assessment\Provider\AiProviderResponse;
use App\Services\Insights\InsightSpecification;
use JsonException;

/**
 * A deterministic, offline model for local development and end-to-end tests
 * (AI_PROVIDER=fake; refused in production by the configuration
 * validator). It reads the evidence JSON between the delimiters of the user
 * message and writes a fixed template interpretation of it: no network, no
 * model. Its output goes through the same validator as any real model's.
 */
final class FakeModelClient implements ModelClient
{
    public const NAME = 'fake';

    public const MODEL = 'codedna-template';

    public function name(): string
    {
        return self::NAME;
    }

    public function model(): string
    {
        return self::MODEL;
    }

    public function health(): ModelHealth
    {
        return new ModelHealth(true, true, 'fake');
    }

    public function complete(AiRequest $request): AiProviderResponse
    {
        $begin = strpos($request->user, InsightSpecification::EVIDENCE_BEGIN);
        $end = strpos($request->user, InsightSpecification::EVIDENCE_END);
        if ($begin === false || $end === false) {
            throw AiProviderException::permanent(AssessmentFailure::ProviderRejected, 'unsupported_request');
        }
        $json = substr($request->user, $begin + strlen(InsightSpecification::EVIDENCE_BEGIN), $end - $begin - strlen(InsightSpecification::EVIDENCE_BEGIN));
        try {
            $payload = json_decode(trim($json), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AiProviderException::permanent(AssessmentFailure::ProviderRejected, 'unsupported_request');
        }
        $items = [];
        foreach ((array) ($payload['evidence'] ?? []) as $item) {
            $items[$item['id']] = $item;
        }

        $output = match ($payload['kind'] ?? null) {
            'GROWTH_INTERPRETATION' => $this->growth($items),
            'ROADMAP_GUIDANCE' => $this->roadmap($items),
            'CHALLENGE_FEEDBACK' => $this->challenge($items),
            default => throw AiProviderException::permanent(AssessmentFailure::ProviderRejected, 'unsupported_request'),
        };

        return new AiProviderResponse(content: (string) json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), servedModel: self::MODEL);
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function growth(array $items): array
    {
        $points = [];
        foreach ($items as $id => $item) {
            $status = $item['facts']['status'] ?? null;
            if (str_starts_with($id, 'obs:') && in_array($status, ['IMPROVED', 'REGRESSED'], true)) {
                $points[] = [
                    'title' => ($status === 'IMPROVED' ? 'Measured improvement: ' : 'Measured regression: ').$item['facts']['metric_key'],
                    'description' => $status === 'IMPROVED'
                        ? 'The growth rules classify this observation as improved between the two assessments.'
                        : 'The growth rules classify this observation as regressed between the two assessments.',
                    'evidence_refs' => [$id],
                ];
            }
        }
        if ($points === []) {
            $points[] = ['title' => 'No measured change', 'description' => 'No observation reached the threshold for a measured change.', 'evidence_refs' => ['growth:summary', 'growth:rules']];
        }

        return $this->output(
            ['text' => 'The comparison lists the measured changes between the two assessments; see the points.', 'evidence_refs' => ['growth:summary']],
            array_slice($points, 0, 6),
            [],
            [['description' => 'Observations with insufficient evidence are not interpreted as change.', 'evidence_refs' => ['growth:rules']]],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function roadmap(array $items): array
    {
        $points = [];
        $next = [];
        foreach ($items as $id => $item) {
            if (! str_starts_with($id, 'track:')) {
                continue;
            }
            $competency = (string) $item['facts']['competency'];
            $refs = [$id];
            if (isset($items['focus:'.$competency])) {
                $refs[] = 'focus:'.$competency;
            }
            $points[] = ['title' => 'Track '.$competency, 'description' => 'This track was selected by a measured skill gap of the analyzed code.', 'evidence_refs' => $refs];
            foreach ($items as $stepId => $step) {
                if (str_starts_with($stepId, 'step:') && ($step['facts']['track'] ?? null) === $competency && ($step['facts']['state'] ?? null) === 'AVAILABLE') {
                    $next[] = ['title' => 'Next step for '.$competency, 'description' => 'Take the next available step of this track.', 'evidence_refs' => [$stepId]];
                    break;
                }
            }
        }

        return $this->output(
            ['text' => 'The roadmap focuses on the tracks listed below, each chosen by a measured skill gap.', 'evidence_refs' => ['roadmap:summary']],
            $points === [] ? [['title' => 'Roadmap', 'description' => 'The roadmap has no tracks to explain.', 'evidence_refs' => ['roadmap:summary']]] : array_slice($points, 0, 6),
            array_slice($next, 0, 4),
            [['description' => 'Completing steps does not change any measured result; only a new code analysis can.', 'evidence_refs' => ['roadmap:summary']]],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function challenge(array $items): array
    {
        $verdict = $items['result:verdict']['facts']['verdict'] ?? 'FAILED';
        $points = [];
        $next = [];
        foreach ($items as $id => $item) {
            $status = $item['facts']['status'] ?? null;
            if (str_starts_with($id, 'criterion:')) {
                $points[] = $status === 'PASSED'
                    ? ['title' => 'Acceptance criterion passed', 'description' => 'The evaluator found this criterion satisfied.', 'evidence_refs' => [$id]]
                    : ['title' => 'Acceptance criterion failed', 'description' => 'The evaluator found this criterion unmet.', 'evidence_refs' => [$id]];
            }
            if (str_starts_with($id, 'rule:') && $status === 'FAILED') {
                $next[] = ['title' => 'Review the failed rule', 'description' => 'Restructure the code so that this structural rule holds, then submit again.', 'evidence_refs' => [$id]];
            }
        }

        return $this->output(
            ['text' => $verdict === 'PASSED' ? 'The evaluator verdict for this attempt is passed.' : 'The evaluator verdict for this attempt is failed.', 'evidence_refs' => ['result:verdict']],
            $points === [] ? [['title' => 'Evaluator result', 'description' => 'See the verdict of this attempt.', 'evidence_refs' => ['result:verdict']]] : array_slice($points, 0, 6),
            array_slice($next, 0, 4),
            [['description' => 'Hidden test cases are known only by their status, and a challenge never changes a CodeDNA result.', 'evidence_refs' => ['challenge:definition']]],
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $points
     * @param  list<array<string, mixed>>  $next
     * @param  list<array<string, mixed>>  $limitations
     * @return array<string, mixed>
     */
    private function output(array $summary, array $points, array $next, array $limitations): array
    {
        return ['schema_version' => InsightSpecification::OUTPUT_SCHEMA_VERSION, 'summary' => $summary, 'points' => $points, 'next_steps' => $next, 'limitations' => $limitations];
    }
}
