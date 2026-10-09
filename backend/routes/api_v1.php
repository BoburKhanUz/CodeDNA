<?php

use App\Http\Controllers\Api\V1\Ai\AiStatusController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\Billing\BillingWebhookController;
use App\Http\Controllers\Api\V1\GitHub\GitHubAccountController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InstallationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Organizations\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationAnalyticsController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationAuditController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationMemberController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationProjectController;
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
use App\Http\Controllers\Api\V1\Projects\InsightController;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\Projects\ProjectGitHubController;
use App\Http\Controllers\Api\V1\Projects\ProjectRepositoryProviderController;
use App\Http\Controllers\Api\V1\Projects\RoadmapController;
use App\Http\Controllers\Api\V1\Projects\RoadmapStepController;
use App\Http\Controllers\Api\V1\Projects\SkillGapSnapshotController;
use App\Http\Controllers\Api\V1\Projects\SourceSnapshotController;
use App\Http\Controllers\Api\V1\Repositories\RepositoryProviderAccountController;
use App\Http\Middleware\RequireSession;
use Illuminate\Support\Facades\Route;

// Routes under /api/v1. Every route here is in the `api` middleware group
// (Sanctum stateful sessions for first-party requests, `throttle:api`).

// Readiness must report its own 503 when Redis is down (Phase 30): the
// throttle keeps its counters in Redis, so it would fail first with a 500.
// The TLS edge rate-limits every path per IP (Nginx limit_req).
Route::get('health', HealthController::class)->withoutMiddleware('throttle:api')->name('health');

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

// The session check every page render makes (Phase 30): its own limiter, so
// polling that spends the per-user API budget never breaks page navigation.
Route::get('me', MeController::class)
    ->withoutMiddleware('throttle:api')
    ->middleware(['auth:sanctum', 'throttle:session'])
    ->name('me');

// The installation's edition, license status and registration mode (Phase 27,
// docs/enterprise/enterprise-architecture.md#status). Read-only, for any signed-in
// user; never the license document, its signature or any configuration value.
Route::get('installation', InstallationController::class)
    ->middleware('auth:sanctum')
    ->name('installation');

// Local AI status (Phase 29, docs/operations/local-ai.md#health): whether AI
// explanations can be requested now. A cached check; never a URL or key.
Route::get('ai/status', AiStatusController::class)
    ->middleware('auth:sanctum')
    ->name('ai.status');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])
        ->middleware('throttle:profile-update')
        ->name('profile.update');
});

// Billing (Phase 23, docs/billing/billing-architecture.md). Read-only for
// users: always the caller's own billing, and no route accepts a plan, a
// price, a quota or a provider reference. Plans change only through verified
// provider webhooks, which are authenticated by their signature, not a session.
Route::middleware(['auth:sanctum', 'throttle:billing-read'])->prefix('billing')->name('billing.')->group(function (): void {
    Route::get('/', [BillingController::class, 'overview'])->name('overview');
    Route::get('plans', [BillingController::class, 'plans'])->name('plans');
    Route::get('usage', [BillingController::class, 'usage'])->name('usage');
    Route::get('subscription', [BillingController::class, 'subscription'])->name('subscription');
});
Route::post('billing/webhooks/{provider}', BillingWebhookController::class)
    ->where('provider', '[a-z][a-z0-9_]{0,31}')
    ->middleware('throttle:billing-webhook')
    ->name('billing.webhook');

// Organizations (Phase 24, docs/teams/teams-architecture.md). Members only:
// an organization the caller does not belong to answers 404. Roles and
// organization state are checked by OrganizationAccess, in the policies and
// again by each action under the organization row lock. Memberships are never
// deleted (DELETE marks them REMOVED); organizations are archived.
//
// Invitation links carry the token in the path; the access log redacts it.
Route::get('organizations/invitations/{token}', [InvitationAcceptanceController::class, 'show'])
    ->where('token', '[A-Za-z0-9_-]{1,128}')
    ->middleware('throttle:invitation-preview')
    ->name('organizations.invitations.preview');
Route::post('organizations/invitations/{token}/accept', [InvitationAcceptanceController::class, 'accept'])
    ->where('token', '[A-Za-z0-9_-]{1,128}')
    ->middleware(['auth:sanctum', 'throttle:invitation-accept'])
    ->name('organizations.invitations.accept');

Route::middleware('auth:sanctum')->prefix('organizations')->name('organizations.')->group(function (): void {
    Route::get('/', [OrganizationController::class, 'index'])->name('index');
    Route::post('/', [OrganizationController::class, 'store'])
        ->middleware('throttle:organization-create')
        ->name('store');
    Route::get('{organization}', [OrganizationController::class, 'show'])->whereUlid('organization')->name('show');
    Route::patch('{organization}', [OrganizationController::class, 'update'])
        ->whereUlid('organization')
        ->middleware('throttle:organization-write')
        ->name('update');
    Route::post('{organization}/archive', [OrganizationController::class, 'archive'])
        ->whereUlid('organization')
        ->middleware('throttle:organization-write')
        ->name('archive');
    Route::get('{organization}/billing', [OrganizationController::class, 'billing'])->whereUlid('organization')->name('billing');

    Route::get('{organization}/members', [OrganizationMemberController::class, 'index'])->whereUlid('organization')->name('members.index');
    Route::patch('{organization}/members/{membership}', [OrganizationMemberController::class, 'update'])
        ->whereUlid(['organization', 'membership'])
        ->scopeBindings()
        ->middleware('throttle:organization-write')
        ->name('members.update');
    Route::delete('{organization}/members/{membership}', [OrganizationMemberController::class, 'destroy'])
        ->whereUlid(['organization', 'membership'])
        ->scopeBindings()
        ->middleware('throttle:organization-write')
        ->name('members.destroy');

    Route::get('{organization}/invitations', [OrganizationInvitationController::class, 'index'])->whereUlid('organization')->name('invitations.index');
    Route::post('{organization}/invitations', [OrganizationInvitationController::class, 'store'])
        ->whereUlid('organization')
        ->middleware('throttle:invitation-create')
        ->name('invitations.store');
    Route::post('{organization}/invitations/{invitation}/revoke', [OrganizationInvitationController::class, 'revoke'])
        ->whereUlid(['organization', 'invitation'])
        ->scopeBindings()
        ->middleware('throttle:organization-write')
        ->name('invitations.revoke');

    Route::get('{organization}/projects', [OrganizationProjectController::class, 'index'])->whereUlid('organization')->name('projects.index');
    Route::post('{organization}/projects', [OrganizationProjectController::class, 'store'])
        ->whereUlid('organization')
        ->middleware('throttle:project-create')
        ->name('projects.store');

    Route::get('{organization}/audit-events', OrganizationAuditController::class)->whereUlid('organization')->name('audit-events.index');
    Route::get('{organization}/analytics', OrganizationAnalyticsController::class)->whereUlid('organization')->name('analytics');
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

    // AI insights (Phase 29): non-authoritative interpretations of a growth
    // snapshot, a learning roadmap or an evaluated challenge submission,
    // generated by the local AI gateway. The client names a kind and a
    // subject only; requests share the AI assessment rate limit and quota.
    Route::get('{project}/insights', [InsightController::class, 'index'])
        ->whereUlid('project')
        ->name('insights.index');
    Route::post('{project}/insights', [InsightController::class, 'store'])
        ->whereUlid('project')
        ->middleware('throttle:assessment-create')
        ->name('insights.store');
    Route::get('{project}/insights/{insight}', [InsightController::class, 'show'])
        ->whereUlid(['project', 'insight'])
        ->name('insights.show');

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

    // GitLab and Bitbucket Cloud (Phase 28): the same connection and import
    // model as GitHub, one source per project. Provider, repository and
    // commit are verified with the provider as the user; the client never
    // supplies a URL. Rate limits are shared with the GitHub routes.
    Route::get('{project}/repository-provider', [ProjectRepositoryProviderController::class, 'show'])
        ->whereUlid('project')
        ->name('repository-provider.show');
    Route::post('{project}/repository-provider', [ProjectRepositoryProviderController::class, 'store'])
        ->whereUlid('project')
        ->middleware('throttle:github-write')
        ->name('repository-provider.store');
    Route::patch('{project}/repository-provider', [ProjectRepositoryProviderController::class, 'update'])
        ->whereUlid('project')
        ->middleware('throttle:github-write')
        ->name('repository-provider.update');
    Route::delete('{project}/repository-provider', [ProjectRepositoryProviderController::class, 'destroy'])
        ->whereUlid('project')
        ->middleware('throttle:github-write')
        ->name('repository-provider.destroy');
    Route::get('{project}/repository-provider/branches', [ProjectRepositoryProviderController::class, 'branches'])
        ->whereUlid('project')
        ->middleware('throttle:github-read')
        ->name('repository-provider.branches');
    Route::get('{project}/repository-provider/imports', [ProjectRepositoryProviderController::class, 'imports'])
        ->whereUlid('project')
        ->name('repository-provider.imports.index');
    Route::post('{project}/repository-provider/imports', [ProjectRepositoryProviderController::class, 'storeImport'])
        ->whereUlid('project')
        ->middleware('throttle:github-import')
        ->name('repository-provider.imports.store');
    Route::get('{project}/repository-provider/imports/{import}', [ProjectRepositoryProviderController::class, 'showImport'])
        ->whereUlid(['project', 'import'])
        ->name('repository-provider.imports.show');

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

// The signed-in user's GitLab and Bitbucket Cloud authorizations (Phase 28).
// Authorization is a browser flow: starting and completing it need the session.
Route::middleware('auth:sanctum')->prefix('repository-providers')->name('repository-providers.')->group(function (): void {
    Route::get('/', [RepositoryProviderAccountController::class, 'index'])->name('index');
    Route::post('{provider}/authorizations', [RepositoryProviderAccountController::class, 'startAuthorization'])
        ->middleware([RequireSession::class, 'throttle:github-authorize'])
        ->name('authorizations.store');
    Route::post('{provider}/callback', [RepositoryProviderAccountController::class, 'callback'])
        ->middleware([RequireSession::class, 'throttle:github-authorize'])
        ->name('callback');
    Route::delete('{provider}', [RepositoryProviderAccountController::class, 'destroy'])
        ->middleware('throttle:github-write')
        ->name('destroy');
    Route::get('{provider}/repositories', [RepositoryProviderAccountController::class, 'repositories'])
        ->middleware('throttle:github-read')
        ->name('repositories');
});
