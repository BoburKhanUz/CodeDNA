<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\Assessment\AssessmentFailure;
use App\Enums\Competency\CompetencyKey;
use App\Enums\Dna\DnaDimension;
use App\Enums\ProgrammingLanguage;
use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
use App\Models\SkillGapSnapshot;
use App\Services\Competency\CompetencySpecification;
use App\Services\Dna\FixedPoint;
use App\Services\Dna\ScoringSpecification;
use App\Services\SkillGap\SkillGapSpecification;
use InvalidArgumentException;

/**
 * Builds the assessment input from one persisted skill gap snapshot and the
 * competency and DNA snapshots it descends from
 * (docs/architecture/ai-assessment-v1.md#input).
 *
 * Allowlist only: every evidence item is assembled field by field from
 * enum-checked keys, decimal strings, integers, booleans and server-owned
 * text (specification descriptions). Nothing derived from uploaded source
 * (project names, file names, finding messages, metadata) is copied, so
 * there is nothing for a prompt injection to ride on. Each snapshot's
 * stored specification fingerprint must match the definition of its
 * version; otherwise the evidence is rejected, never reinterpreted.
 */
final class AssessmentInputBuilder
{
    /** Short labels of the DNA 1.0.0 components (fixed product text). */
    private const COMPONENT_LABELS = [
        'mean_cyclomatic_complexity' => 'Average cyclomatic complexity',
        'complex_function_share' => 'Functions above the complexity threshold',
        'deep_nesting_share' => 'Deeply nested functions',
        'long_function_share' => 'Long functions',
        'long_parameter_list_share' => 'Long parameter lists',
        'large_type_share' => 'Large types',
        'syntax_error_share' => 'Files with syntax errors',
    ];

    /**
     * @throws AssessmentException EVIDENCE_INVALID
     */
    public function build(SkillGapSnapshot $gaps, AssessmentSpecification $spec): AssessmentInput
    {
        $gaps->loadMissing(['competencySnapshot.dnaSnapshot', 'results']);
        $competency = $gaps->competencySnapshot;
        $dna = $competency?->dnaSnapshot;
        if ($competency === null || $dna === null) {
            throw new AssessmentException(AssessmentFailure::EvidenceInvalid, 'lineage');
        }
        [$scoringSpec, $competencySpec, $gapSpec] = $this->specifications($gaps, $competency, $dna);

        $evidence = [
            $this->quality($dna),
            $this->profile($gapSpec),
            ...$this->dimensions($dna, $scoringSpec),
            ...$this->competencies($competency, $competencySpec),
            ...$this->gaps($gaps),
            ...$this->languages($competency, $competencySpec),
        ];
        usort($evidence, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return new AssessmentInput(
            lineage: [
                'project_id' => $gaps->project_id,
                'user_id' => $gaps->user_id,
                'skill_gap_snapshot_id' => $gaps->id,
                'competency_snapshot_id' => $competency->id,
                'dna_snapshot_id' => $dna->id,
                'analysis_run_id' => $dna->analysis_run_id,
                'source_snapshot_id' => $dna->source_snapshot_id,
                'result_hash' => $dna->result_hash,
                'dna_specification_fingerprint' => $scoringSpec->fingerprint(),
                'competency_specification_fingerprint' => $competencySpec->fingerprint(),
                'skill_gap_specification_fingerprint' => $gapSpec->fingerprint(),
                'assessment_specification_fingerprint' => $spec->fingerprint(),
            ],
            payload: [
                'schema_version' => AssessmentSpecification::INPUT_SCHEMA_VERSION,
                'assessment_version' => $spec->version(),
                'versions' => [
                    'dna_scoring' => $dna->scoring_version,
                    'competency' => $competency->competency_version,
                    'skill_gap' => $gaps->skill_gap_version,
                    'target_profile' => $gaps->target_profile,
                    'target_profile_version' => $gaps->target_profile_version,
                    'analyzer' => $this->version($dna->analyzer_version),
                    'metrics' => $this->version($dna->metrics_version),
                ],
                'statuses' => [
                    'dna_snapshot' => $dna->status->value,
                    'competency_snapshot' => $competency->status->value,
                    'skill_gap_snapshot' => $gaps->status->value,
                ],
                'notes' => [
                    'Scores, levels, gaps, priorities and targets are deterministic and authoritative.',
                    'Scores are on a 0 to 1 scale; a higher score is better.',
                    'Only code characteristics listed here were measured; nothing else about the code is known.',
                ],
                'evidence' => $evidence,
            ],
        );
    }

    /**
     * @return array{0: ScoringSpecification, 1: CompetencySpecification, 2: SkillGapSpecification}
     */
    private function specifications(SkillGapSnapshot $gaps, CompetencySnapshot $competency, DnaSnapshot $dna): array
    {
        try {
            $scoring = ScoringSpecification::forVersion($dna->scoring_version);
            $competencySpec = CompetencySpecification::forVersion($competency->competency_version);
            $gapSpec = SkillGapSpecification::forVersion($gaps->skill_gap_version);
        } catch (InvalidArgumentException) {
            throw new AssessmentException(AssessmentFailure::EvidenceInvalid, 'version');
        }
        $evidence = $dna->evidence ?? [];
        if (($evidence['specification_fingerprint'] ?? null) !== $scoring->fingerprint()
            || $competency->specification_fingerprint !== $competencySpec->fingerprint()
            || $gaps->specification_fingerprint !== $gapSpec->fingerprint()
            || $gaps->target_profile !== $gapSpec->targetProfile->key
            || $gaps->target_profile_version !== $gapSpec->targetProfile->version) {
            throw new AssessmentException(AssessmentFailure::EvidenceInvalid, 'fingerprint');
        }

        return [$scoring, $competencySpec, $gapSpec];
    }

    /**
     * @return array<string, mixed>
     */
    private function quality(DnaSnapshot $dna): array
    {
        $quality = ($dna->evidence ?? [])['data_quality'] ?? [];
        $term = fn (string $name, string $key): mixed => is_array($quality[$name] ?? null) ? ($quality[$name][$key] ?? null) : null;

        return $this->item('quality:data', 'Data quality', 'How much of the analyzed code could be measured: parse coverage, evidence volume and metric availability.', [
            'data_quality' => $this->decimal($dna->data_quality),
            'parse_coverage' => $this->decimal($term('parse_coverage', 'value')),
            'evidence_volume' => $this->decimal($term('evidence_volume', 'value')),
            'metric_availability' => $this->decimal($term('metric_availability', 'value')),
            'files_parsed' => $this->count($term('parse_coverage', 'metrics.overall.files_parsed')),
            'files_analyzable' => $this->count($term('parse_coverage', 'metrics.overall.files_analyzable')),
            'functions' => $this->count($term('evidence_volume', 'metrics.overall.functions_total')),
            'components_available' => $this->count($term('metric_availability', 'available_components')),
            'components_total' => $this->count($term('metric_availability', 'components')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(SkillGapSpecification $spec): array
    {
        $profile = $spec->targetProfile;

        return $this->item('profile:'.$profile->key, 'Target profile', $profile->description, [
            'version' => $profile->version,
            'material_gap_threshold' => FixedPoint::format($spec->materialGapThreshold),
        ]);
    }

    /**
     * One item per DNA dimension and one per component, in specification order.
     *
     * @return list<array<string, mixed>>
     */
    private function dimensions(DnaSnapshot $dna, ScoringSpecification $spec): array
    {
        $items = [];
        foreach ($spec->toArray()['dimensions'] as $definition) {
            $dimension = DnaDimension::from($definition['id']);
            $stored = $dna->dimensions[$dimension->value] ?? null;
            $stored = is_array($stored) ? $stored : [];
            $items[] = $this->item('dna:'.$dimension->value, $dimension->displayName(), $definition['description'], [
                'status' => $this->token($stored['status'] ?? 'MISSING'),
                'score' => $this->decimal($stored['score'] ?? null),
                'unavailable_reason' => isset($stored['unavailable_reason']) ? $this->token($stored['unavailable_reason']) : null,
            ]);
            foreach ($definition['components'] as $component) {
                $key = $component['key'];
                $measured = is_array($stored['components'][$key] ?? null) ? $stored['components'][$key] : [];
                $items[] = $this->item("component:{$dimension->value}.{$key}", self::COMPONENT_LABELS[$key] ?? $key, $component['description'], [
                    'status' => $this->token($measured['status'] ?? 'MISSING'),
                    'value' => $this->decimal($measured['value'] ?? null, FixedPoint::ONE * 1000),
                    'score' => $this->decimal($measured['score'] ?? null),
                ]);
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function competencies(CompetencySnapshot $snapshot, CompetencySpecification $spec): array
    {
        $stored = [];
        foreach ($snapshot->competencies as $competency) {
            if (is_array($competency) && is_string($competency['key'] ?? null)) {
                $stored[$competency['key']] = $competency;
            }
        }

        $items = [];
        foreach ($spec->competencies as $definition) {
            $key = $definition->key;
            $competency = $stored[$key->value] ?? [];
            $items[] = $this->item('competency:'.$key->value, $key->displayName(), $definition->description, [
                'status' => $this->token($competency['status'] ?? 'MISSING'),
                'level' => isset($competency['level']) ? $this->token($competency['level']) : null,
                'score' => $this->decimal($competency['score'] ?? null),
                'evidence_quality' => $this->decimal($competency['evidence_quality'] ?? null),
                'evidence' => array_map(fn ($rule): string => 'component:'.$rule->source(), $definition->evidence),
                'limited_languages' => $this->limitedLanguages($competency['limitations'] ?? []),
            ]);
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gaps(SkillGapSnapshot $snapshot): array
    {
        $items = [];
        foreach ($snapshot->results as $result) {
            $key = CompetencyKey::tryFrom($result->competency_key)
                ?? throw new AssessmentException(AssessmentFailure::EvidenceInvalid, 'competency');
            $items[] = $this->item('gap:'.$key->value, 'Gap: '.$key->displayName(), 'Difference between the competency score and the target of the profile; only a material gap has a priority.', [
                'status' => $result->status->value,
                'current_score' => $this->decimal($result->current_score),
                'target_score' => $this->decimal($result->target_score),
                'raw_gap' => $this->decimal($result->raw_gap),
                'material_gap' => $result->material_gap,
                'priority' => $result->priority?->value,
                'priority_capped' => $result->priority_capped,
                'competency' => 'competency:'.$key->value,
            ]);
        }

        return $items;
    }

    /**
     * Languages measured in the analyzed code (allowlisted identifiers only),
     * with the specification's note where support is partial.
     *
     * @return list<array<string, mixed>>
     */
    private function languages(CompetencySnapshot $snapshot, CompetencySpecification $spec): array
    {
        $notes = [];
        foreach ($spec->competencies as $definition) {
            $notes += $definition->partialLanguages;
        }
        $languages = $snapshot->provenance['languages'] ?? [];
        $items = [];
        foreach (is_array($languages) ? $languages : [] as $language) {
            $known = is_string($language) ? ProgrammingLanguage::tryFrom($language) : null;
            if ($known === null) {
                continue;
            }
            $items[$known->value] = $this->item('language:'.$known->value, 'Language: '.$known->value, 'A language present in the analyzed code.', [
                'partial_support' => isset($notes[$known->value]),
                'note' => $notes[$known->value] ?? null,
            ]);
        }

        return array_values($items);
    }

    /**
     * @return list<string>
     */
    private function limitedLanguages(mixed $limitations): array
    {
        $languages = [];
        foreach (is_array($limitations) ? $limitations : [] as $limitation) {
            $language = is_array($limitation) && is_string($limitation['language'] ?? null) ? ProgrammingLanguage::tryFrom($limitation['language']) : null;
            if ($language !== null) {
                $languages[] = 'language:'.$language->value;
            }
        }
        sort($languages);

        return array_values(array_unique($languages));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function item(string $id, string $label, string $description, array $facts): array
    {
        if (preg_match('#'.AssessmentSpecification::EVIDENCE_REF_PATTERN.'#', $id) !== 1) {
            throw new AssessmentException(AssessmentFailure::EvidenceInvalid, 'evidence_id');
        }

        return ['id' => $id, 'kind' => strstr($id, ':', true), 'label' => $label, 'description' => $description, 'facts' => $facts];
    }

    /**
     * A stored decimal string, re-formatted; anything else is invalid.
     */
    private function decimal(mixed $value, int $maximum = FixedPoint::ONE): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            $units = is_string($value) ? FixedPoint::parse($value) : -1;
        } catch (InvalidArgumentException) {
            $units = -1;
        }
        if ($units < 0 || $units > $maximum) {
            throw new AssessmentException(AssessmentFailure::EvidenceInvalid, 'decimal');
        }

        return FixedPoint::format($units);
    }

    private function count(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new AssessmentException(AssessmentFailure::EvidenceInvalid, 'count');
        }

        return $value;
    }

    /**
     * An upper-case status token such as AVAILABLE or INSUFFICIENT_EVIDENCE.
     */
    private function token(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Z][A-Z_]{0,63}$/', $value) !== 1) {
            throw new AssessmentException(AssessmentFailure::EvidenceInvalid, 'token');
        }

        return $value;
    }

    private function version(?string $value): ?string
    {
        return $value !== null && preg_match('/^[0-9A-Za-z.+-]{1,32}$/', $value) === 1 ? $value : null;
    }
}
