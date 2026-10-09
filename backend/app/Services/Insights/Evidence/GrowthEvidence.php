<?php

declare(strict_types=1);

namespace App\Services\Insights\Evidence;

use App\Enums\Growth\GrowthSnapshotStatus;
use App\Enums\Insights\InsightFailure;
use App\Models\GrowthObservation;
use App\Models\GrowthSnapshot;
use App\Services\Growth\GrowthRules;
use App\Services\Insights\InsightException;

/**
 * Evidence for a growth interpretation (Phase 29): one COMPARED growth
 * snapshot (Phase 18) and its observations, copied field by field from
 * the stored deterministic comparison. Nothing is recomputed: statuses,
 * values and deltas are exactly what growth stored. A snapshot without a
 * comparable baseline has nothing to interpret and is refused.
 */
final class GrowthEvidence
{
    /** More than every metric of rules 1.0.0 (about 25); a bound, not a selection. */
    public const MAX_OBSERVATIONS = 64;

    private const DESCRIPTIONS = [
        'IMPROVED' => 'Measured improvement between the two assessments under the growth rules.',
        'REGRESSED' => 'Measured regression between the two assessments under the growth rules.',
        'UNCHANGED' => 'Both sides measured; the difference is below the meaningful threshold, so no change is claimed.',
        'INSUFFICIENT_EVIDENCE' => 'No change is claimed: a side is unmeasured or its evidence quality is below the minimum.',
    ];

    /**
     * @return array{evidence: list<array<string, mixed>>, lineage: array<string, string|null>, notes: list<string>}
     */
    public static function build(GrowthSnapshot $snapshot): array
    {
        if ($snapshot->status !== GrowthSnapshotStatus::Compared) {
            throw new InsightException(InsightFailure::EvidenceInvalid, 'growth_not_compared');
        }
        $rules = GrowthRules::forVersion($snapshot->rules_version);
        if ($snapshot->rules_fingerprint !== $rules->fingerprint()) {
            throw new InsightException(InsightFailure::EvidenceInvalid, 'growth_rules_fingerprint');
        }
        $observations = GrowthObservation::query()->where('growth_snapshot_id', $snapshot->id)->orderBy('position')->limit(self::MAX_OBSERVATIONS + 1)->get();
        if ($observations->count() > self::MAX_OBSERVATIONS) {
            throw new InsightException(InsightFailure::InputTooLarge, 'observations');
        }

        $summary = (array) $snapshot->summary;
        $statuses = [];
        foreach ((array) ($summary['statuses'] ?? []) as $type => $counts) {
            foreach ((array) $counts as $status => $count) {
                $statuses[Facts::token($type)][Facts::token($status)] = Facts::count($count);
            }
        }
        $levels = (array) ($summary['level_changes'] ?? []);
        $rulesData = $rules->toArray();
        $evidence = [
            Facts::item('growth:summary', 'Growth summary', 'Counts of observations per metric type and status, and competency level changes.', [
                'observations' => Facts::count($summary['observations'] ?? null),
                'statuses' => $statuses,
                'level_changes' => ['UP' => Facts::count($levels['UP'] ?? 0), 'DOWN' => Facts::count($levels['DOWN'] ?? 0)],
            ]),
            Facts::item('growth:rules', 'Growth rules', 'A change is claimed only at or above the meaningful delta, and only when both sides meet the minimum evidence quality.', [
                'version' => Facts::version($rulesData['version']),
                'meaningful_delta' => Facts::decimal($rulesData['meaningful_delta']),
                'minimum_evidence_quality' => Facts::decimal($rulesData['minimum_evidence_quality']),
            ]),
        ];
        foreach ($observations as $o) {
            /** @var GrowthObservation $o */
            $type = Facts::token($o->metric_type->value);
            $key = Facts::token($o->metric_key);
            $status = Facts::token($o->status->value);
            $evidence[] = Facts::item("obs:{$type}:{$key}", "{$type} {$key} (".($o->better === 'LOWER' ? 'lower' : 'higher').' is better)', self::DESCRIPTIONS[$status] ?? Facts::invalid('growth_status'), [
                'metric_type' => $type,
                'metric_key' => $key,
                'status' => $status,
                'previous_state' => Facts::token($o->previous_state),
                'current_state' => Facts::token($o->current_state),
                'previous_value' => Facts::decimal($o->previous_value),
                'current_value' => Facts::decimal($o->current_value),
                'delta' => Facts::signedDecimal($o->delta),
                'previous_level' => Facts::optionalToken($o->previous_level),
                'current_level' => Facts::optionalToken($o->current_level),
                'level_change' => Facts::optionalToken($o->level_change),
                'previous_evidence_quality' => Facts::decimal($o->previous_evidence_quality),
                'current_evidence_quality' => Facts::decimal($o->current_evidence_quality),
            ]);
        }

        return [
            'evidence' => $evidence,
            'lineage' => [
                'project_id' => $snapshot->project_id,
                'user_id' => $snapshot->user_id,
                'growth_snapshot_id' => $snapshot->id,
                'skill_gap_snapshot_id' => $snapshot->skill_gap_snapshot_id,
                'previous_skill_gap_snapshot_id' => $snapshot->previous_skill_gap_snapshot_id,
            ],
            'notes' => [
                'Growth compares two deterministic assessments of the same project. Only observations with status IMPROVED or REGRESSED are measured change.',
                'UNCHANGED means the difference is below the meaningful threshold; INSUFFICIENT_EVIDENCE means no change can be claimed.',
                'More analyzed files, a different project selection or a newly available measurement is never improvement by itself.',
                'Completed learning steps and challenges are not growth evidence.',
            ],
        ];
    }
}
