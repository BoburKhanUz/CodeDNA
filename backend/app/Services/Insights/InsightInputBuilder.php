<?php

declare(strict_types=1);

namespace App\Services\Insights;

use App\Enums\Insights\InsightFailure;
use App\Enums\Insights\InsightKind;
use App\Models\ChallengeSubmission;
use App\Models\GrowthSnapshot;
use App\Models\RoadmapSnapshot;
use App\Services\Insights\Evidence\ChallengeEvidence;
use App\Services\Insights\Evidence\GrowthEvidence;
use App\Services\Insights\Evidence\RoadmapEvidence;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Builds the canonical input of an insight from its stored subject (Phase
 * 29). Deterministic: the same stored evidence always gives the same input,
 * byte for byte, and therefore the same fingerprint. Callers authorize
 * access to the project first; the builder only ever reads the one subject
 * it is given and rows that belong to it.
 */
final class InsightInputBuilder
{
    public function build(InsightKind $kind, Model $subject, InsightSpecification $spec): InsightInput
    {
        $built = match ($kind) {
            InsightKind::GrowthInterpretation => $subject instanceof GrowthSnapshot ? GrowthEvidence::build($subject) : null,
            InsightKind::RoadmapGuidance => $subject instanceof RoadmapSnapshot ? RoadmapEvidence::build($subject) : null,
            InsightKind::ChallengeFeedback => $subject instanceof ChallengeSubmission ? ChallengeEvidence::build($subject) : null,
        } ?? throw new InvalidArgumentException('The subject does not match the insight kind.');

        $evidence = $built['evidence'];
        usort($evidence, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        if (count(array_unique(array_column($evidence, 'id'))) !== count($evidence)) {
            throw new InsightException(InsightFailure::EvidenceInvalid, 'evidence_id_duplicate');
        }

        return new InsightInput($kind, [
            'schema_version' => InsightSpecification::INPUT_SCHEMA_VERSION,
            'insight_version' => $spec->version(),
            'kind' => $kind->value,
            'notes' => $built['notes'],
            'evidence' => $evidence,
        ], $built['lineage']);
    }

    /**
     * The subject of $kind with $id in $projectId, or null.
     */
    public function subject(InsightKind $kind, string $projectId, string $id): ?Model
    {
        $query = match ($kind) {
            InsightKind::GrowthInterpretation => GrowthSnapshot::query(),
            InsightKind::RoadmapGuidance => RoadmapSnapshot::query(),
            InsightKind::ChallengeFeedback => ChallengeSubmission::query(),
        };

        return $query->where('project_id', $projectId)->whereKey($id)->first();
    }
}
