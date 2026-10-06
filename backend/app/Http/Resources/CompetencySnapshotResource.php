<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Competency\CompetencyKey;
use App\Enums\Competency\CompetencyLevel;
use App\Enums\Competency\CompetencyStatus;
use App\Models\CompetencySnapshot;
use App\Services\Competency\CompetencyDefinition;
use App\Services\Competency\CompetencySpecification;
use App\Services\Dna\FixedPoint;
use App\Services\Dna\ScoringSpecification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;

/**
 * One competency snapshot with everything the Competency Matrix page shows
 * (GET /api/v1/projects/{project}/competencies/{snapshot},
 * docs/api/README.md#competencies).
 *
 * Presentation only: scores, levels, evidence quality and evidence are read
 * from the stored snapshot and passed through unchanged (4-place decimal
 * strings, raw counts, null where nothing was assessed). The descriptions,
 * evidence rationales and level bounds come from the snapshot's own
 * competency version. No analyzer payload, storage detail or owner ID.
 *
 * @mixin CompetencySnapshot
 */
final class CompetencySnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $spec = $this->specification();
        $provenance = $this->provenance ?? [];
        $dna = $this->dnaSnapshot;

        return [
            'id' => $this->id,
            'type' => 'competency_snapshot',
            'project_id' => $this->project_id,
            'dna_snapshot_id' => $this->dna_snapshot_id,
            'analysis_run_id' => $this->analysis_run_id,
            'source_snapshot_id' => $this->source_snapshot_id,
            'status' => $this->status->value,
            'competency_version' => $this->competency_version,
            'specification_fingerprint' => $this->specification_fingerprint,
            'dna_scoring_version' => $this->dna_scoring_version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'levels' => $spec === null ? null : array_map(fn (string $level, int $minimum): array => [
                'level' => $level,
                'name' => CompetencyLevel::from($level)->displayName(),
                'ordinal' => CompetencyLevel::from($level)->ordinal(),
                'minimum_score' => FixedPoint::format($minimum),
            ], array_keys($spec->levels), array_values($spec->levels)),
            'summary' => self::summary($this->summary ?? []),
            'languages' => is_array($provenance['languages'] ?? null) ? array_values($provenance['languages']) : null,
            'dna_snapshot' => $dna === null ? null : [
                'id' => $dna->id,
                'status' => $dna->status->value,
                'overall_score' => $dna->overall_score,
                'data_quality' => $dna->data_quality,
                'scoring_version' => $dna->scoring_version,
                'specification_fingerprint' => is_string($provenance['dna_specification_fingerprint'] ?? null) ? $provenance['dna_specification_fingerprint'] : null,
                'created_at' => $dna->created_at?->toIso8601ZuluString(),
            ],
            'source_snapshot' => $this->sourceSnapshot === null ? null : [
                'id' => $this->sourceSnapshot->id,
                'version' => $this->sourceSnapshot->version,
                'file_count' => $this->sourceSnapshot->file_count,
                'primary_language' => $this->sourceSnapshot->primary_language,
                'created_at' => $this->sourceSnapshot->created_at?->toIso8601ZuluString(),
            ],
            'analysis_run' => $this->analysisRun === null ? null : [
                'id' => $this->analysisRun->id,
                'result_type' => $this->analysisRun->result_type->value,
                'status' => $this->analysisRun->status->value,
                'completed_at' => $this->analysisRun->completed_at?->toIso8601ZuluString(),
            ],
            'competencies' => $this->competencyList($spec),
        ];
    }

    /**
     * Stored counts per status and level, in the enums' fixed order.
     *
     * @param  array<array-key, mixed>  $summary
     * @return array{competencies: int, statuses: array<string, int>, levels: array<string, int>}
     */
    public static function summary(array $summary): array
    {
        $statuses = [];
        foreach (CompetencyStatus::cases() as $status) {
            $statuses[$status->value] = (int) ($summary['statuses'][$status->value] ?? 0);
        }
        $levels = [];
        foreach (CompetencyLevel::cases() as $level) {
            $levels[$level->value] = (int) ($summary['levels'][$level->value] ?? 0);
        }

        return ['competencies' => (int) ($summary['competencies'] ?? 0), 'statuses' => $statuses, 'levels' => $levels];
    }

    private function specification(): ?CompetencySpecification
    {
        try {
            return CompetencySpecification::forVersion($this->competency_version);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function competencyList(?CompetencySpecification $spec): array
    {
        $definitions = [];
        foreach ($spec->competencies ?? [] as $definition) {
            $definitions[$definition->key->value] = $definition;
        }

        $list = [];
        foreach ($this->competencies ?? [] as $competency) {
            if (! is_array($competency)) {
                continue;
            }
            $key = (string) ($competency['key'] ?? '');
            $definition = $definitions[$key] ?? null;
            $list[] = [
                'key' => $key,
                'name' => $competency['name'] ?? CompetencyKey::tryFrom($key)?->displayName() ?? $key,
                'description' => $definition?->description,
                'status' => $competency['status'] ?? null,
                'score' => $competency['score'] ?? null,
                'level' => $competency['level'] ?? null,
                'evidence_quality' => $competency['evidence_quality'] ?? null,
                'evidence_quality_terms' => $competency['evidence_quality_terms'] ?? null,
                'limitations' => array_values((array) ($competency['limitations'] ?? [])),
                'evidence' => $this->evidenceList((array) ($competency['evidence'] ?? []), $definition),
            ];
        }

        return $list;
    }

    /**
     * @param  array<array-key, mixed>  $evidence
     * @return list<array<string, mixed>>
     */
    private function evidenceList(array $evidence, ?CompetencyDefinition $definition): array
    {
        $rationales = [];
        foreach ($definition->evidence ?? [] as $rule) {
            $rationales[$rule->source()] = $rule->rationale;
        }
        $shares = $this->shares();

        $list = [];
        foreach ($evidence as $item) {
            if (! is_array($item)) {
                continue;
            }
            $source = (string) ($item['source'] ?? '');
            $list[] = [
                'source' => $source,
                'dimension' => $item['dimension'] ?? null,
                'component' => $item['component'] ?? null,
                'rationale' => $rationales[$source] ?? null,
                'share' => $shares[$source] ?? null,
                'weight' => $item['weight'] ?? null,
                'required' => $item['required'] ?? null,
                'status' => $item['status'] ?? null,
                'value' => $item['value'] ?? null,
                'score' => $item['score'] ?? null,
                'best' => $item['best'] ?? null,
                'worst' => $item['worst'] ?? null,
                'minimum_denominator' => $item['minimum_denominator'] ?? null,
                'numerator' => self::counts($item['numerator'] ?? []),
                'denominator' => self::counts($item['denominator'] ?? []),
            ];
        }

        return $list;
    }

    /**
     * "DIMENSION.component" => whether its value is a share, from the DNA
     * scoring specification the evidence came from.
     *
     * @return array<string, bool>
     */
    private function shares(): array
    {
        try {
            $spec = ScoringSpecification::forVersion($this->dna_scoring_version);
        } catch (InvalidArgumentException) {
            return [];
        }
        $shares = [];
        foreach ($spec->dimensions as $dimension) {
            foreach ($dimension->components as $component) {
                $shares["{$dimension->dimension->value}.{$component->key}"] = $component->share;
            }
        }

        return $shares;
    }

    /**
     * @return list<array{metric: string, value: int|null}>
     */
    private static function counts(mixed $counts): array
    {
        $list = [];
        foreach ((array) $counts as $metric => $value) {
            $list[] = ['metric' => (string) $metric, 'value' => is_int($value) ? $value : null];
        }
        usort($list, fn (array $a, array $b): int => $a['metric'] <=> $b['metric']);

        return $list;
    }
}
