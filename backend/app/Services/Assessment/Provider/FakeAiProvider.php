<?php

declare(strict_types=1);

namespace App\Services\Assessment\Provider;

use App\Services\Assessment\AssessmentInput;
use App\Services\Assessment\AssessmentPrompt;

/**
 * A deterministic, offline provider for local development and end-to-end
 * tests (AI_PROVIDER=fake). It builds a template interpretation directly
 * from the evidence statuses: no network, no model, no cost. The
 * configuration validator refuses it in production.
 *
 * Its output goes through the same validator as any real provider's.
 */
final class FakeAiProvider implements AiProvider
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

    public function generateAssessment(AssessmentInput $input, AssessmentPrompt $prompt): AiProviderResponse
    {
        $strengths = [];
        $areas = [];
        $insights = [];
        $limitations = [[
            'description' => 'Only the measured code characteristics are covered; testing, security, performance and documentation are not measured.',
            'evidence_refs' => ['quality:data'],
        ]];

        foreach ($input->evidence() as $item) {
            if ($item['kind'] !== 'gap') {
                continue;
            }
            $competency = (string) $item['facts']['competency'];
            $name = (string) ($input->evidenceItem($competency)['label'] ?? $competency);
            $refs = [$item['id'], $competency];
            $status = $item['facts']['status'];
            if ($status === 'NO_GAP') {
                $strengths[] = [
                    'title' => $name,
                    'description' => "The measured evidence for {$name} meets the target of the profile.",
                    'evidence_refs' => $refs,
                ];
            } elseif ($status === 'GAP') {
                $areas[] = [
                    'title' => $name,
                    'description' => "The measured evidence for {$name} is below the target of the profile.",
                    'evidence_refs' => $refs,
                ];
                $insights[] = [
                    'title' => $name,
                    'description' => "The evidence items listed for {$name} show which measured characteristics account for the difference.",
                    'evidence_refs' => [$competency],
                ];
            } else {
                $limitations[] = [
                    'description' => "{$name} could not be compared with the target because its evidence is not sufficient or not supported.",
                    'evidence_refs' => $refs,
                ];
            }
        }

        $summary = match (true) {
            $areas !== [] => 'Some measured competencies are below the target of the profile; the areas to improve list them with their evidence.',
            $strengths !== [] => 'The measured competencies meet the target of the profile.',
            default => 'There is not enough measured evidence for an interpretation; see the limitations.',
        };

        $output = [
            'schema_version' => 'assessment/v1',
            'summary' => ['text' => $summary, 'evidence_refs' => ['quality:data']],
            'strengths' => array_slice($strengths, 0, 5),
            'areas_to_improve' => array_slice($areas, 0, 5),
            'development_insights' => array_slice($insights, 0, 5),
            'limitations' => array_slice($limitations, 0, 6),
        ];

        return new AiProviderResponse(content: (string) json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), servedModel: self::MODEL);
    }
}
