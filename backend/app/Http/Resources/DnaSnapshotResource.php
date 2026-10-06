<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Dna\DnaDimension;
use App\Enums\Dna\EvidenceStatus;
use App\Models\DnaSnapshot;
use App\Services\Dna\ScoringSpecification;
use App\Services\Dna\Specification\DimensionSpec;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;

/**
 * One DNA snapshot with everything the dashboard shows
 * (GET /api/v1/projects/{project}/dna/{snapshot}, docs/api/README.md#dna).
 *
 * Presentation only: every score, weight, contribution and evidence count is
 * read from the stored snapshot and passed through unchanged (decimal strings
 * with 4 places, raw integer counts, null where the engine stored null).
 * Nothing is recomputed. The only additions are the descriptions and the
 * `share` flag of the snapshot's own scoring specification, and a fixed,
 * documented order: JSONB does not keep key order.
 *
 * Never included: the analyzer result, source contents, storage details or
 * URLs, and the internal owner ID.
 *
 * @mixin DnaSnapshot
 */
final class DnaSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $spec = $this->specification();
        $evidence = $this->evidence ?? [];

        return [
            'id' => $this->id,
            'type' => 'dna_snapshot',
            'project_id' => $this->project_id,
            'analysis_run_id' => $this->analysis_run_id,
            'source_snapshot_id' => $this->source_snapshot_id,
            'status' => $this->status->value,
            'overall_score' => $this->overall_score,
            'data_quality' => $this->data_quality,
            'scoring_version' => $this->scoring_version,
            'specification_fingerprint' => is_string($evidence['specification_fingerprint'] ?? null) ? $evidence['specification_fingerprint'] : null,
            'versions' => [
                'scoring' => $this->scoring_version,
                'metrics' => $this->metrics_version,
                'analyzer' => $this->analyzer_version,
                'ir' => $this->ir_version,
                'contract' => $this->contract_version,
            ],
            'result_hash' => $this->result_hash,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
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
            'dimensions' => $this->dimensionList($spec),
            'aggregation' => $this->aggregation($evidence['aggregation'] ?? null),
            'availability' => $this->availability($evidence['availability'] ?? null),
            'data_quality_breakdown' => $this->dataQualityBreakdown($evidence['data_quality'] ?? null, $spec),
        ];
    }

    private function specification(): ?ScoringSpecification
    {
        try {
            return ScoringSpecification::forVersion($this->scoring_version);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Dimensions in the specification's order (unknown ones last, by identifier).
     *
     * @return list<array<string, mixed>>
     */
    private function dimensionList(?ScoringSpecification $spec): array
    {
        $stored = array_filter($this->dimensions ?? [], 'is_array');
        $order = array_map(fn (DnaDimension $d): string => $d->value, DnaDimension::cases());
        uksort($stored, function (string $a, string $b) use ($order): int {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);

            return [$ia === false ? PHP_INT_MAX : $ia, $a] <=> [$ib === false ? PHP_INT_MAX : $ib, $b];
        });

        $specs = [];
        foreach ($spec->dimensions ?? [] as $dimensionSpec) {
            $specs[$dimensionSpec->dimension->value] = $dimensionSpec;
        }

        $list = [];
        foreach ($stored as $id => $dimension) {
            $dimensionSpec = $specs[$id] ?? null;
            $list[] = [
                'dimension' => (string) $id,
                'name' => $dimension['name'] ?? DnaDimension::tryFrom((string) $id)?->displayName() ?? (string) $id,
                'description' => $dimensionSpec?->description,
                'status' => $dimension['status'] ?? null,
                'unavailable_reason' => $dimension['unavailable_reason'] ?? null,
                'score' => $dimension['score'] ?? null,
                'weight' => $dimension['weight'] ?? null,
                'effective_weight' => $dimension['effective_weight'] ?? null,
                'contribution' => $dimension['contribution'] ?? null,
                'data_quality' => $dimension['data_quality'] ?? null,
                'components' => $this->componentList((array) ($dimension['components'] ?? []), $dimensionSpec),
            ];
        }

        return $list;
    }

    /**
     * @param  array<array-key, mixed>  $stored
     * @return list<array<string, mixed>>
     */
    private function componentList(array $stored, ?DimensionSpec $spec): array
    {
        $specs = [];
        foreach ($spec->components ?? [] as $componentSpec) {
            $specs[$componentSpec->key] = $componentSpec;
        }
        $keys = array_keys($stored);
        $known = array_values(array_filter(array_keys($specs), fn (string $key): bool => array_key_exists($key, $stored)));
        $other = array_values(array_diff($keys, $known));
        sort($other);

        $list = [];
        foreach ([...$known, ...$other] as $key) {
            $component = $stored[$key];
            if (! is_array($component)) {
                continue;
            }
            $componentSpec = $specs[$key] ?? null;
            $list[] = [
                'key' => (string) $key,
                'description' => $componentSpec?->description,
                'share' => $componentSpec?->share,
                'status' => $component['status'] ?? null,
                'required' => $component['required'] ?? null,
                'weight' => $component['weight'] ?? null,
                'value' => $component['value'] ?? null,
                'score' => $component['score'] ?? null,
                'best' => $component['best'] ?? null,
                'worst' => $component['worst'] ?? null,
                'minimum_denominator' => $component['minimum_denominator'] ?? null,
                'numerator' => $this->metrics((array) ($component['numerator'] ?? []), $componentSpec?->numerator),
                'denominator' => $this->metrics((array) ($component['denominator'] ?? []), $componentSpec?->denominator),
            ];
        }

        return $list;
    }

    /**
     * Metric path => raw count (null when the engine had no value), as a list
     * in the specification's order.
     *
     * @param  array<array-key, mixed>  $stored
     * @param  list<string>|null  $order
     * @return list<array{metric: string, value: int|null}>
     */
    private function metrics(array $stored, ?array $order): array
    {
        $paths = array_map('strval', array_keys($stored));
        $ordered = $order === null ? [] : array_values(array_filter($order, fn (string $p): bool => in_array($p, $paths, true)));
        $rest = array_values(array_diff($paths, $ordered));
        sort($rest);

        return array_map(fn (string $path): array => [
            'metric' => $path,
            'value' => is_int($stored[$path]) ? $stored[$path] : null,
        ], [...$ordered, ...$rest]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function aggregation(mixed $stored): ?array
    {
        if (! is_array($stored)) {
            return null;
        }

        return [
            'method' => $stored['method'] ?? null,
            'minimum_scored_dimensions' => $stored['minimum_scored_dimensions'] ?? null,
            'scored_dimensions' => array_values((array) ($stored['scored_dimensions'] ?? [])),
            'unavailable_dimensions' => array_values((array) ($stored['unavailable_dimensions'] ?? [])),
            'scored_weight' => $stored['scored_weight'] ?? null,
            'renormalized' => $stored['renormalized'] ?? null,
        ];
    }

    /**
     * Component counts per evidence status, in a fixed order.
     *
     * @return array<string, int>|null
     */
    private function availability(mixed $stored): ?array
    {
        if (! is_array($stored)) {
            return null;
        }
        $counts = [];
        foreach (EvidenceStatus::cases() as $status) {
            $counts[$status->value] = (int) ($stored[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * The stored data-quality terms, with the metric paths of the snapshot's
     * specification turned into stable field names.
     *
     * @return array<string, mixed>|null
     */
    private function dataQualityBreakdown(mixed $stored, ?ScoringSpecification $spec): ?array
    {
        if (! is_array($stored) || $spec === null) {
            return null;
        }
        $q = $spec->dataQuality;
        $coverage = (array) ($stored['parse_coverage'] ?? []);
        $volume = (array) ($stored['evidence_volume'] ?? []);
        $availability = (array) ($stored['metric_availability'] ?? []);

        return [
            'parse_coverage' => [
                'value' => $coverage['value'] ?? null,
                'weight' => $coverage['weight'] ?? null,
                'files_parsed' => $coverage[$q->parsedFiles] ?? null,
                'files_analyzable' => $coverage[$q->analyzableFiles] ?? null,
            ],
            'evidence_volume' => [
                'value' => $volume['value'] ?? null,
                'weight' => $volume['weight'] ?? null,
                'functions' => $volume[$q->evidenceVolume] ?? null,
                'target' => $volume['target'] ?? null,
            ],
            'metric_availability' => [
                'value' => $availability['value'] ?? null,
                'weight' => $availability['weight'] ?? null,
                'available_components' => $availability['available_components'] ?? null,
                'components' => $availability['components'] ?? null,
            ],
        ];
    }
}
