<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\GitHub\GitHubAccountController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Profile\ProfileController;
use App\Http\Controllers\Api\V1\Projects\AnalysisController;
use App\Http\Controllers\Api\V1\Projects\ArchiveProjectController;
use App\Http\Controllers\Api\V1\Projects\AssessmentController;
use App\Http\Controllers\Api\V1\Projects\ChallengeController;
use App\Http\Controllers\Api\V1\Projects\ChallengeSubmissionController;
use App\Http\Controllers\Api\V1\Projects\CompetencySnapshotController;
use App\Http\Controllers\Api\V1\Projects\DnaSnapshotController;
use App\Http\Controllers\Api\V1\Projects\GitHubImportController;
use App\Http\Controllers\Api\V1\Projects\GrowthController;
use App\Http\Controllers\Api\V1\Projects\HistoryController;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\Projects\ProjectGitHubController;
use App\Http\Controllers\Api\V1\Projects\RoadmapController;
use App\Http\Controllers\Api\V1\Projects\RoadmapStepController;
use App\Http\Controllers\Api\V1\Projects\SkillGapSnapshotController;
use App\Http\Controllers\Api\V1\Projects\SourceSnapshotController;
use App\Http\Middleware\RequireSession;
use Illuminate\Support\Facades\Route;

// Routes under /api/v1. Every route here is in the `api` middleware group
// (Sanctum stateful sessions for first-party requests, `throttle:api`).

Route::get('health', HealthController::class)->name('health');

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', RegisterController::class)
        ->middleware([RequireSession::class, 'throttle:register'])
        ->name('register');

    Route::post('login', LoginController::class)
        ->middleware([RequireSession::class, 'throttle:login'])
        ->name('login');

    Route::post('logout', LogoutController::class)
        ->middleware(['auth:sanctum', RequireSession::class])
        ->name('logout');

    // Ends the session: the client signs in again with the new password.
    Route::patch('password', PasswordController::class)
        ->middleware(['auth:sanctum', RequireSession::class, 'throttle:password-change'])
        ->name('password');
});

Route::get('me', MeController::class)
    ->middleware('auth:sanctum')
    ->name('me');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])
        ->middleware('throttle:profile-update')
        ->name('profile.update');
});

// Projects and immutable source snapshots (Phase 07). Owner-only: other
// users' projects answer 404 (ProjectPolicy). No DELETE routes: projects are
// archived, and snapshots never change.
Route::middleware('auth:sanctum')->prefix('projects')->name('projects.')->group(function (): void {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::post('/', [ProjectController::class, 'store'])
        ->middleware('throttle:project-create')
        ->name('store');
    Route::get('{project}', [ProjectController::class, 'show'])->whereUlid('project')->name('show');
    Route::patch('{project}', [ProjectController::class, 'update'])
        ->whereUlid('project')
        ->middleware('throttle:project-update')
        ->name('update');
    Route::post('{project}/archive', ArchiveProjectController::class)
        ->whereUlid('project')
        ->middleware('throttle:project-update')
        ->name('archive');

    Route::get('{project}/source-snapshots', [SourceSnapshotController::class, 'index'])
        ->whereUlid('project')
        ->name('source-snapshots.index');
    Route::post('{project}/source-snapshots', [SourceSnapshotController::class, 'store'])
        ->whereUlid('project')
        ->middleware('throttle:source-upload')
        ->name('source-snapshots.store');
    Route::get('{project}/source-snapshots/{sourceSnapshot}', [SourceSnapshotController::class, 'show'])
        ->whereUlid(['project', 'sourceSnapshot'])
        ->scopeBindings()
        ->name('source-snapshots.show');

    // Analysis runs (Phase 10): start (or get the equivalent run), list, inspect, result.
    Route::get('{project}/analyses', [AnalysisController::class, 'index'])
        ->whereUlid('project')
        ->name('analyses.index');
    Route::post('{project}/analyses', [AnalysisController::class, 'store'])
        ->whereUlid('project')
        ->middleware('throttle:analysis-create')
        ->name('analyses.store');
    Route::get('{project}/analyses/{analysisRun}', [AnalysisController::class, 'show'])
        ->whereUlid(['project', 'analysisRun'])
        ->scopeBindings()
        ->name('analyses.show');
    Route::get('{project}/analyses/{analysisRun}/result', [AnalysisController::class, 'result'])
        ->whereUlid(['project', 'analysisRun'])
        ->scopeBindings()
        ->name('analyses.result');

    // DNA snapshots (Phase 12): read-only. They are created by the scoring
    // engine (Phase 11) and never changed, so there is no write route.
    Route::get('{project}/dna', [DnaSnapshotController::class, 'index'])
        ->whereUlid('project')
        ->name('dna.index');
    Route::get('{project}/dna/{dnaSnapshot}', [DnaSnapshotController::class, 'show'])
        ->whereUlid(['project', 'dnaSnapshot'])
        ->scopeBindings()
        ->name('dna.show');

    // Competency snapshots (Phase 13): read-only, derived from DNA snapshots
    // by the competency engine and never changed.
    Route::get('{project}/competencies', [CompetencySnapshotController::class, 'index'])
        ->whereUlid('project')
        ->name('competencies.index');
    Route::get('{project}/competencies/{competencySnapshot}', [CompetencySnapshotController::class, 'show'])
        ->whereUlid(['project', 'competencySnapshot'])
        ->scopeBindings()
        ->name('competencies.show');

    // Skill gap snapshots (Phase 14): read-only, derived from competency
    // snapshots against server-owned target profiles and never changed.
    Route::get('{project}/skill-gaps', [SkillGapSnapshotController::class, 'index'])
        ->whereUlid('project')
        ->name('skill-gaps.index');
    Route::get('{project}/skill-gaps/{skillGapSnapshot}', [SkillGapSnapshotController::class, 'show'])
        ->whereUlid(['project', 'skillGapSnapshot'])
        ->scopeBindings()
        ->name('skill-gaps.show');

    // AI assessments (Phase 15): non-authoritative interpretations of a skill
    // gap snapshot. POST only queues generation; there is no update or delete.
    Route::get('{project}/assessments', [AssessmentController::class, 'index'])
        ->whereUlid('project')
        ->name('assessments.index');
    Route::post('{project}/assessments', [AssessmentController::class, 'store'])
        ->whereUlid('project')
        ->middleware('throttle:assessment-create')
        ->name('assessments.store');
    Route::get('{project}/assessments/{aiAssessment}', [AssessmentController::class, 'show'])
        ->whereUlid(['project', 'aiAssessment'])
        ->scopeBindings()
        ->name('assessments.show');

    // Coding challenges (Phase 16): a practice layer selected from skill
    // gaps. Assigning and submitting only record and queue; submitted code
    // runs in the isolated evaluator. No update or delete routes.
    Route::get('{project}/challenges', [ChallengeController::class, 'index'])
        ->whereUlid('project')
        ->name('challenges.index');
    Route::post('{project}/challenges', [ChallengeController::class, 'store'])
        ->whereUlid('project')
        ->middleware('throttle:challenge-assign')
        ->name('challenges.store');
    Route::get('{project}/challenges/{challengeInstance}', [ChallengeController::class, 'show'])
        ->whereUlid(['project', 'challengeInstance'])
        ->scopeBindings()
        ->name('challenges.show');
    Route::get('{project}/challenges/{challengeInstance}/submissions', [ChallengeSubmissionController::class, 'index'])
        ->whereUlid(['project', 'challengeInstance'])
        ->scopeBindings()
        ->name('challenges.submissions.index');
    Route::post('{project}/challenges/{challengeInstance}/submissions', [ChallengeSubmissionController::class, 'store'])
        ->whereUlid(['project', 'challengeInstance'])
        ->scopeBindings()
        ->middleware('throttle:challenge-submit')
        ->name('challenges.submissions.store');
    Route::get('{project}/challenges/{challengeInstance}/submissions/{challengeSubmission}', [ChallengeSubmissionController::class, 'show'])
        ->whereUlid(['project', 'challengeInstance', 'challengeSubmission'])
        ->scopeBindings()
        ->name('challenges.submissions.show');

    // Growth tracking (Phase 18): read-only observations over successive
    // deterministic assessments, generated by the analysis pipeline. Learning
    // activity is never growth evidence. No write routes.
    Route::get('{project}/growth', [GrowthController::class, 'overview'])
        ->whereUlid('project')
        ->name('growth.overview');
    Route::get('{project}/growth/timeline', [GrowthController::class, 'timeline'])
        ->whereUlid('project')
        ->name('growth.timeline');
    Route::get('{project}/growth/{growthSnapshot}', [GrowthController::class, 'show'])
        ->whereUlid(['project', 'growthSnapshot'])
        ->scopeBindings()
        ->name('growth.show');

    // Historical DNA (Phase 20): a read-only view of every stored assessment
    // of the project as it was recorded. Clients send only pagination and
    // the IDs of two points to compare. No write routes.
    Route::get('{project}/history', [HistoryController::class, 'index'])
        ->whereUlid('project')
        ->name('history.index');
    Route::get('{project}/history/compare', [HistoryController::class, 'compare'])
        ->whereUlid('project')
        ->name('history.compare');
    Route::get('{project}/history/{dnaSnapshot}', [HistoryController::class, 'show'])
        ->whereUlid(['project', 'dnaSnapshot'])
        ->name('history.show');

    // GitHub integration (Phase 19): the project's repository connection and
    // imports into source snapshots. Repository, installation and commit are
    // always verified with GitHub; the client never supplies them.
    Route::get('{project}/github', [ProjectGitHubController::class, 'show'])
        ->whereUlid('project')
        ->name('github.show');
    Route::post('{project}/github', [ProjectGitHubController::class, 'store'])
        ->whereUlid('project')
        ->middleware('throttle:github-write')
        ->name('github.store');
    Route::patch('{project}/github', [ProjectGitHubController::class, 'update'])
        ->whereUlid('project')
        ->middleware('throttle:github-write')
        ->name('github.update');
    Route::delete('{project}/github', [ProjectGitHubController::class, 'destroy'])
        ->whereUlid('project')
        ->middleware('throttle:github-write')
        ->name('github.destroy');
    Route::get('{project}/github/branches', [ProjectGitHubController::class, 'branches'])
        ->whereUlid('project')
        ->middleware('throttle:github-read')
        ->name('github.branches');
    Route::get('{project}/github/imports', [GitHubImportController::class, 'index'])
        ->whereUlid('project')
        ->name('github.imports.index');
    Route::post('{project}/github/imports', [GitHubImportController::class, 'store'])
        ->whereUlid('project')
        ->middleware('throttle:github-import')
        ->name('github.imports.store');
    Route::get('{project}/github/imports/{githubImport}', [GitHubImportController::class, 'show'])
        ->whereUlid(['project', 'githubImport'])
        ->scopeBindings()
        ->name('github.imports.show');

    // Learning roadmaps (Phase 17): a planning layer generated from the newest
    // skill gap analysis. Generating and completing steps change only roadmap
    // records, never CodeDNA, competencies or gaps. No update or delete routes.
    Route::get('{project}/roadmaps', [RoadmapController::class, 'index'])
        ->whereUlid('project')
        ->name('roadmaps.index');
    Route::post('{project}/roadmaps', [RoadmapController::class, 'store'])
        ->whereUlid('project')
        ->middleware('throttle:roadmap-generate')
        ->name('roadmaps.store');
    Route::get('{project}/roadmaps/{roadmapSnapshot}', [RoadmapController::class, 'show'])
        ->whereUlid(['project', 'roadmapSnapshot'])
        ->scopeBindings()
        ->name('roadmaps.show');
    Route::post('{project}/roadmaps/{roadmapSnapshot}/steps/{step}/complete', [RoadmapStepController::class, 'complete'])
        ->whereUlid(['project', 'roadmapSnapshot'])
        ->where('step', '(cm|fd|ts|ch)-[a-z0-9]+(-[a-z0-9]+)*')
        ->scopeBindings()
        ->middleware('throttle:roadmap-progress')
        ->name('roadmaps.steps.complete');
});

// The signed-in user's GitHub authorization (Phase 19). Authorization is a
// browser flow: starting it and completing it need the session.
Route::middleware('auth:sanctum')->prefix('github')->name('github.')->group(function (): void {
    Route::get('/', [GitHubAccountController::class, 'show'])->name('show');
    Route::post('authorizations', [GitHubAccountController::class, 'startAuthorization'])
        ->middleware([RequireSession::class, 'throttle:github-authorize'])
        ->name('authorizations.store');
    Route::post('callback', [GitHubAccountController::class, 'callback'])
        ->middleware([RequireSession::class, 'throttle:github-authorize'])
        ->name('callback');
    Route::delete('/', [GitHubAccountController::class, 'destroy'])
        ->middleware('throttle:github-write')
        ->name('destroy');
    Route::get('installations', [GitHubAccountController::class, 'installations'])
        ->middleware('throttle:github-read')
        ->name('installations');
    Route::get('installations/{installation}/repositories', [GitHubAccountController::class, 'repositories'])
        ->where('installation', '[1-9][0-9]{0,17}')
        ->middleware('throttle:github-read')
        ->name('installations.repositories');
});
