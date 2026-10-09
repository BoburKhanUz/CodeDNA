<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Challenge\AssignChallenge;
use App\Actions\Challenge\SubmitChallengeSolution;
use App\Actions\Growth\CalculateGrowthSnapshot;
use App\Actions\Roadmap\GenerateRoadmap;
use App\Jobs\EvaluateChallengeSubmission;
use App\Models\ChallengeSubmission;
use App\Models\GrowthSnapshot;
use App\Models\Project;
use App\Models\RoadmapSnapshot;
use App\Models\User;
use App\Services\Challenge\Evaluator\ChallengeEvaluator;
use Illuminate\Support\Carbon;

/**
 * Real deterministic subjects for AI insight tests (Phase 29), produced by
 * the real engines (growth, roadmap, challenge evaluation with the fake
 * evaluator). Nothing is hand-written into those tables.
 */
final class InsightFixtures
{
    /** A COMPARED growth snapshot: many gaps, then an hour later only one. */
    public static function growth(Project $project): GrowthSnapshot
    {
        $first = ChallengeFixtures::manyGaps($project);
        app(CalculateGrowthSnapshot::class)->handle($first->id);
        Carbon::setTestNow(Carbon::now()->addHour());
        $second = ChallengeFixtures::oneGap($project);
        app(CalculateGrowthSnapshot::class)->handle($second->id);

        return GrowthSnapshot::query()->where('skill_gap_snapshot_id', $second->id)->sole();
    }

    /** The NOT_ESTABLISHED growth snapshot of a single assessment. */
    public static function baselineOnly(Project $project): GrowthSnapshot
    {
        $gaps = ChallengeFixtures::manyGaps($project);
        app(CalculateGrowthSnapshot::class)->handle($gaps->id);

        return GrowthSnapshot::query()->where('skill_gap_snapshot_id', $gaps->id)->sole();
    }

    public static function roadmap(Project $project, User $owner): RoadmapSnapshot
    {
        ChallengeFixtures::manyGaps($project);

        return app(GenerateRoadmap::class)->handle($project, $owner)->roadmap;
    }

    /** An evaluated submission; $mode is a FakeChallengeEvaluator mode ("pass", "wrong", "static", ...). */
    public static function submission(Project $project, User $owner, string $mode = 'pass'): ChallengeSubmission
    {
        app()->instance(ChallengeEvaluator::class, new FakeChallengeEvaluator($mode));
        ChallengeFixtures::manyGaps($project);
        $challenge = app(AssignChallenge::class)->handle($project, $owner, null, 'FUNCTION_DESIGN')->instance;
        $submission = app(SubmitChallengeSolution::class)->handle($challenge, $owner, 'python', "SOURCE_SECRET_MARKER = 'never sent'\ndef summarize(order):\n    return {}\n", null)->submission;
        app()->call([(new EvaluateChallengeSubmission($submission->id))->withFakeQueueInteractions(), 'handle']);

        return ChallengeSubmission::query()->findOrFail($submission->id);
    }
}
