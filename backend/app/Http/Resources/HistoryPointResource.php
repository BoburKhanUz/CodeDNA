<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Competency\CompetencyKey;
use App\Enums\Dna\DnaDimension;
use App\Enums\Growth\GrowthMetricType;
use App\Enums\Growth\GrowthStatus;
use App\Enums\SourceType;
use App\Models\SkillGapResult;
use App\Services\Growth\GrowthEvents;
use App\Services\History\HistoryPoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One point of a project's historical DNA
 * (docs/api/README.md#historical-dna): the stored DNA, competency and skill
 * gap values of one assessment, their versions, the source's provenance,
 * and a link to the assessment's Phase 18 growth.
 *
 * Presentation only. Every value is read from an immutable snapshot and
 * passed through unchanged; a layer that was never calculated is null with
 * an explicit UNAVAILABLE, and a missing value is null, never 0. Never
 * included: storage disks, keys or URLs, analyzer payloads, owner IDs,
 * GitHub installation or token data, or source contents.
 *
 * @property HistoryPoint $resource
 */
final class HistoryPointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $point = $this->resource;
        $versions = $point->assessment()->versions;
        $layers = $point->layers();

        return [
            'id' => $point->dna->id,
            'type' => 'history_point',
            'project_id' => $point->dna->project_id,
            'analyzed_at' => $point->analyzedAt()->toIso8601ZuluString(),
            'analysis_run_id' => $point->run->id,
            'layers' => [
                'dna' => 'AVAILABLE',
                'competency' => in_array('COMPETENCY', $layers, true) ? 'AVAILABLE' : 'UNAVAILABLE',
                'skill_gaps' => in_array('SKILL_GAP', $layers, true) ? 'AVAILABLE' : 'UNAVAILABLE',
            ],
            'versions' => $versions,
            // Points with equal keys were measured alike, per layer; a trend never crosses a key change.
            'segments' => [
                'dna' => self::segment($versions, ['dna_scoring_version', 'dna_specification_fingerprint', 'metrics_version']),
                'competency' => in_array('COMPETENCY', $layers, true)
                    ? self::segment($versions, ['dna_scoring_version', 'dna_specification_fingerprint', 'metrics_version', 'competency_version', 'competency_specification_fingerprint'])
                    : null,
                'skill_gaps' => in_array('SKILL_GAP', $layers, true) ? self::segment($versions, array_keys($versions)) : null,
            ],
            'dna' => $this->dna($point),
            'competency' => $this->competency($point),
            'skill_gaps' => $this->skillGaps($point),
            'source' => $this->source($point),
            'growth' => $this->growth($point),
        ];
    }

    /**
     * @param  array<string, string|null>  $versions
     * @param  list<string>  $fields
     */
    private static function segment(array $versions, array $fields): string
    {
        $values = array_map(fn (string $field): ?string => $versions[$field] ?? null, $fields);

        return substr(hash('sha256', (string) json_encode($values)), 0, 16);
    }

    /**
     * @return array<string, mixed>
     */
    private function dna(HistoryPoint $point): array
    {
        $dna = $point->dna;
        $stored = array_filter($dna->dimensions, 'is_array');
        $dimensions = [];
        foreach (DnaDimension::cases() as $dimension) {
            $entry = $stored[$dimension->value] ?? null;
            unset($stored[$dimension->value]);
            $dimensions[] = self::dimension($dimension->value, $dimension->displayName(), $entry);
        }
        ksort($stored, SORT_STRING);
        foreach ($stored as $key => $entry) {
            $dimensions[] = self::dimension((string) $key, (string) ($entry['name'] ?? $key), $entry);
        }

        return [
            'snapshot_id' => $dna->id,
            'status' => $dna->status->value,
            'overall_score' => $dna->overall_score,
            'data_quality' => $dna->data_quality,
            'scoring_version' => $dna->scoring_version,
            'specification_fingerprint' => is_string($dna->evidence['specification_fingerprint'] ?? null) ? $dna->evidence['specification_fingerprint'] : null,
            'metrics_version' => $dna->metrics_version,
            'created_at' => $dna->created_at?->toIso8601ZuluString(),
            'dimensions' => $dimensions,
        ];
    }

    /**
     * A dimension as stored; one the snapshot does not contain is MISSING with null values.
     *
     * @param  array<string, mixed>|null  $entry
     * @return array<string, mixed>
     */
    private static function dimension(string $key, string $name, ?array $entry): array
    {
        return [
            'dimension' => $key,
            'name' => $name,
            'status' => $entry === null ? 'MISSING' : (is_string($entry['status'] ?? null) ? $entry['status'] : 'UNAVAILABLE'),
            'score' => is_string($entry['score'] ?? null) ? $entry['score'] : null,
            'data_quality' => is_string($entry['data_quality'] ?? null) ? $entry['data_quality'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function competency(HistoryPoint $point): ?array
    {
        $snapshot = $point->competency;
        if ($snapshot === null) {
            return null;
        }
        $competencies = [];
        foreach ($snapshot->competencies as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = (string) ($entry['key'] ?? '');
            $competencies[] = [
                'key' => $key,
                'name' => CompetencyKey::tryFrom($key)?->displayName() ?? $key,
                'status' => $entry['status'] ?? null,
                'score' => is_string($entry['score'] ?? null) ? $entry['score'] : null,
                'level' => is_string($entry['level'] ?? null) ? $entry['level'] : null,
                'evidence_quality' => is_string($entry['evidence_quality'] ?? null) ? $entry['evidence_quality'] : null,
            ];
        }

        return [
            'snapshot_id' => $snapshot->id,
            'status' => $snapshot->status->value,
            'competency_version' => $snapshot->competency_version,
            'specification_fingerprint' => $snapshot->specification_fingerprint,
            'created_at' => $snapshot->created_at?->toIso8601ZuluString(),
            'competencies' => $competencies,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function skillGaps(HistoryPoint $point): ?array
    {
        $snapshot = $point->competency === null ? null : $point->skillGaps;
        if ($snapshot === null) {
            return null;
        }

        return [
            'snapshot_id' => $snapshot->id,
            'status' => $snapshot->status->value,
            'skill_gap_version' => $snapshot->skill_gap_version,
            'target_profile' => $snapshot->target_profile,
            'target_profile_version' => $snapshot->target_profile_version,
            'specification_fingerprint' => $snapshot->specification_fingerprint,
            'created_at' => $snapshot->created_at?->toIso8601ZuluString(),
            'results' => $snapshot->results->map(fn (SkillGapResult $result): array => [
                'competency_key' => $result->competency_key,
                'name' => CompetencyKey::tryFrom($result->competency_key)?->displayName() ?? $result->competency_key,
                'status' => $result->status->value,
                'current_score' => $result->current_score,
                'target_score' => $result->target_score,
                'gap' => $result->raw_gap,
                'material_gap' => $result->material_gap,
                'priority' => $result->priority?->value,
                'evidence_quality' => $result->evidence_quality,
                'current_level' => $result->current_level,
            ])->values()->all(),
        ];
    }

    /**
     * Where the analyzed code came from. GitHub provenance is the
     * repository, ref and commit recorded at import; nothing else of the
     * import (installation, repository ID, tokens, URLs) is exposed.
     *
     * @return array<string, mixed>
     */
    private function source(HistoryPoint $point): array
    {
        $source = $point->source;
        $provenance = $source->metadata['provenance'] ?? null;
        $github = null;
        $origin = $source->source_type === SourceType::Upload ? 'UPLOAD' : 'REPOSITORY';
        if ($source->source_type === SourceType::Repository && is_array($provenance) && ($provenance['provider'] ?? null) === 'github') {
            $origin = 'GITHUB';
            $repository = $provenance['repository'] ?? null;
            $ref = $provenance['ref'] ?? null;
            $commit = $provenance['commit_sha'] ?? null;
            $github = [
                'repository' => is_string($repository) && preg_match('~^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$~', $repository) === 1 ? $repository : null,
                'ref' => is_string($ref) && $ref !== '' && strlen($ref) <= 255 && preg_match('~[\x00-\x20\x7f]~', $ref) === 0 ? $ref : null,
                'commit_sha' => is_string($commit) && preg_match('~^[0-9a-f]{40}$~', $commit) === 1 ? $commit : null,
            ];
        }

        return [
            'snapshot_id' => $source->id,
            'version' => $source->version,
            'origin' => $origin,
            'source_hash' => $source->source_hash,
            'file_count' => $source->file_count,
            'primary_language' => $source->primary_language,
            'created_at' => $source->created_at?->toIso8601ZuluString(),
            'github' => $github,
        ];
    }

    /**
     * A growth summary (stored or in memory) in a fixed order: JSONB does
     * not keep key order. Counts are passed through, never recomputed.
     *
     * @param  array<array-key, mixed>  $summary
     * @return array{observations: int, statuses: array<string, array<string, int>>, level_changes: array{UP: int, DOWN: int}}
     */
    public static function growthSummary(array $summary): array
    {
        $statuses = [];
        foreach (GrowthMetricType::cases() as $type) {
            foreach (GrowthStatus::cases() as $status) {
                $statuses[$type->value][$status->value] = (int) ($summary['statuses'][$type->value][$status->value] ?? 0);
            }
        }

        return [
            'observations' => (int) ($summary['observations'] ?? 0),
            'statuses' => $statuses,
            'level_changes' => ['UP' => (int) ($summary['level_changes']['UP'] ?? 0), 'DOWN' => (int) ($summary['level_changes']['DOWN'] ?? 0)],
        ];
    }

    /**
     * The assessment's stored Phase 18 growth, as a link and a summary.
     *
     * @return array<string, mixed>|null
     */
    private function growth(HistoryPoint $point): ?array
    {
        $growth = $point->growth;
        if ($growth === null) {
            return null;
        }

        return [
            'id' => $growth->id,
            'status' => $growth->status->value,
            'previous_dna_snapshot_id' => $growth->previous_dna_snapshot_id,
            'previous_assessed_at' => $growth->previous_assessed_at?->toIso8601ZuluString(),
            'rules_version' => $growth->rules_version,
            'summary' => self::growthSummary($growth->summary),
            'events' => GrowthEvents::from($growth->observations),
        ];
    }
}
