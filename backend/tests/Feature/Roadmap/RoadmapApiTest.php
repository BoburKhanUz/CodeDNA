<?php

declare(strict_types=1);

namespace Tests\Feature\Roadmap;

use App\Actions\Challenge\AssignChallenge;
use App\Models\Project;
use App\Models\RoadmapSnapshot;
use App\Models\RoadmapStepCompletion;
use App\Models\SkillGapSnapshot;
use App\Models\User;
use App\Services\Assessment\Provider\AiProvider;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Roadmap\RoadmapCatalog;
use App\Services\Roadmap\RoadmapRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Tests\Feature\SkillGap\SkillGapApiTest;
use Tests\Support\AssessmentFixtures;
use Tests\Support\ChallengeFixtures;
use Tests\Support\StoredResults;
use Tests\TestCase;

/**
 * The learning roadmap API (Phase 17): deterministic generation from the
 * newest skill gap analysis, owner-only access, self-reported progress, and
 * no effect on any deterministic result. No AI is involved
 * (AI_ENABLED=false throughout).
 */
final class RoadmapApiTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = 'Completing learning steps does not change your CodeDNA score or skill gap. Improvement is measured through new code analysis.';

    private User $owner;

    private Project $project;

    /** @var list<MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['codedna.ai.enabled' => false]);
        // Any use of the AI provider fails the test.
        $this->app->bind(AiProvider::class, fn () => throw new RuntimeException('Roadmaps must never use the AI provider.'));
        Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->logs[] = $log);
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
    }

    private function path(string $suffix = '', ?Project $project = null): string
    {
        return '/api/v1/projects/'.($project ?? $this->project)->id."/roadmaps{$suffix}";
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function generate(array $body = [], ?User $as = null, ?Project $project = null): TestResponse
    {
        return $this->asUser($as ?? $this->owner)->postJson($this->path('', $project), $body);
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function complete(string $roadmapId, string $step, ?User $as = null, ?Project $project = null): TestResponse
    {
        return $this->asUser($as ?? $this->owner)->postJson($this->path("/{$roadmapId}/steps/{$step}/complete", $project));
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function show(string $roadmapId, ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->owner)->getJson($this->path("/{$roadmapId}"));
    }

    /**
     * Completes every step of the roadmap, in order.
     */
    private function completeAll(string $roadmapId): TestResponse
    {
        $response = null;
        foreach ($this->show($roadmapId)->json('data.tracks') as $track) {
            foreach ($track['steps'] as $step) {
                $response = $this->complete($roadmapId, $step['key'])->assertOk();
            }
        }
        assert($response instanceof TestResponse);

        return $response;
    }

    public function test_a_roadmap_is_generated_from_the_newest_skill_gap_analysis(): void
    {
        $gaps = ChallengeFixtures::manyGaps($this->project);

        $response = $this->generate()->assertCreated();

        $data = $response->json('data');
        $this->assertSame('learning_roadmap', $data['type']);
        $this->assertSame('ACTIVE', $data['status']);
        $this->assertSame(self::NOTICE, $data['notice']);
        $this->assertSame(['TYPE_STRUCTURE', 'COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN'], $data['focus']);
        $this->assertSame(['completed' => 0, 'total' => 21], $data['progress']);
        $this->assertSame(720, $data['estimated_minutes']);
        $this->assertSame(['TYPE_STRUCTURE', 'COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN'], array_column($data['tracks'], 'key'));
        $this->assertSame([
            'skill_gap_snapshot_id' => $gaps->id, 'competency_snapshot_id' => $gaps->competency_snapshot_id, 'dna_snapshot_id' => $gaps->dna_snapshot_id,
            'analysis_run_id' => $gaps->analysis_run_id, 'source_snapshot_id' => $gaps->source_snapshot_id,
        ], $data['lineage']);
        $this->assertSame(['roadmap' => '1.0.0', 'rules' => '1.0.0', 'skill_gap' => '1.0.0',
            'target_profile' => ['key' => 'ENGINEERING_STANDARD', 'version' => '1.0.0'], 'challenge_catalog' => '1.0.0'], $data['versions']);
        $this->assertSame('937b1e7f209819a06377d009112a6f003ac6c462ddab6b9a3ae804b75e759e63', $data['fingerprints']['catalog']);
        $this->assertSame('eeac05dd35054808855ae1446bf1ef4e943a173a4b4b624b909a6cb6c6dbd60f', $data['fingerprints']['rules']);
        $this->assertSame($gaps->specification_fingerprint, $data['fingerprints']['skill_gap_specification']);
        $this->assertSame(['catalog' => true, 'rules' => true], $data['current']);

        // Development focus with provenance: the gap's values as stored, and why it ranks where it does.
        $first = $data['development_focus']['selected'][0];
        $this->assertSame(['TYPE_STRUCTURE', 'GAP', 'HIGH', '0.0000', '0.7500', '0.7500', '0.9000', 'NOT_ESTABLISHED', 1, 'RAW_GAP', 'COMPLEXITY_MANAGEMENT'], [
            $first['competency_key'], $first['status'], $first['priority'], $first['current_score'], $first['target_score'], $first['raw_gap'],
            $first['evidence_quality'], $first['current_level'], $first['rank'], $first['deciding_criterion'], $first['ranked_above'],
        ]);
        $this->assertSame([['CODE_HYGIENE', 'TRACK_LIMIT']], array_map(fn (array $e): array => [$e['competency_key'], $e['reason']], $data['development_focus']['excluded']));

        // Steps in order, with challenge practice for the CHALLENGE step only.
        $steps = $data['tracks'][0]['steps'];
        $this->assertSame(['ts-responsibility', 'ts-find-oversized', 'ts-separate', 'ts-boundaries', 'ts-challenge', 'ts-reassess'], array_column($steps, 'key'));
        $this->assertSame([true, false, false, false, false, false], array_column($steps, 'can_complete'));
        $this->assertSame(['key' => 'TYPE_STRUCTURE_001', 'version' => '1.0.0', 'title' => 'Split the inventory type', 'difficulty' => 'INTERMEDIATE', 'in_catalog' => true], $steps[4]['challenge']);
        $this->assertNull($steps[4]['practice']);
        $this->assertSame('REASSESS', $steps[5]['type']);
        $this->assertStringContainsString('run a new CodeDNA analysis', $steps[5]['description']);

        $this->assertContains('roadmap.generated', array_map(fn (MessageLogged $l) => $l->message, $this->logs));
        $this->assertSame(1, RoadmapSnapshot::query()->count());
    }

    /**
     * Idempotent: the same snapshot and versions always give the same roadmap, once.
     */
    public function test_generation_is_idempotent(): void
    {
        ChallengeFixtures::manyGaps($this->project);
        $first = $this->generate()->assertCreated()->json('data');

        $again = $this->generate()->assertOk()->assertHeader('Idempotent-Replayed', 'true')->json('data');

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame($first['fingerprints']['roadmap'], $again['fingerprints']['roadmap']);
        $this->assertSame(1, RoadmapSnapshot::query()->count());
        $this->assertSame(21, DB::table('roadmap_steps')->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function clientContent(): array
    {
        return [
            'a target' => [['target_score' => '0.1000']],
            'a competency' => [['competency_key' => 'FUNCTION_DESIGN']],
            'a snapshot' => [['skill_gap_snapshot_id' => '01k6p0a1b2c3d4e5f6g7h8j9sg']],
            'a track' => [['tracks' => [['key' => 'SECURITY', 'steps' => [['title' => 'x']]]]]],
            'a version' => [['roadmap_version' => '9.9.9']],
            'a link' => [['url' => 'https://example.com/course']],
        ];
    }

    /**
     * The client cannot supply roadmap content, targets, gaps, versions or links.
     *
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('clientContent')]
    public function test_clients_cannot_supply_roadmap_content(array $body): void
    {
        ChallengeFixtures::manyGaps($this->project);

        $response = $this->generate($body)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertStringNotContainsString((string) array_key_first($body), (string) $response->getContent());
        $this->assertSame(0, RoadmapSnapshot::query()->count());
    }

    public function test_a_project_without_skill_gaps_has_no_roadmap(): void
    {
        $this->generate()->assertStatus(409)->assertJsonPath('error.code', 'ROADMAP_NO_SKILL_GAPS');
        $this->asUser($this->owner)->getJson($this->path())->assertOk()->assertJsonPath('meta.total', 0);
    }

    /**
     * No material gap and too little evidence are never a learning need.
     */
    public function test_no_actionable_gap_gives_no_roadmap(): void
    {
        AssessmentFixtures::skillGaps($this->project, SkillGapApiTest::noGaps(...));
        $this->generate()->assertStatus(409)->assertJsonPath('error.code', 'ROADMAP_NO_ACTIONABLE_GAPS');

        $other = Project::factory()->for($this->owner)->create();
        $insufficient = AssessmentFixtures::skillGaps($other, function (stdClass $result): void {
            StoredResults::set($result, ['files_analyzable' => 2, 'files_parsed' => 2, 'files_parse_error' => 0, 'functions_total' => 2, 'complexity_total' => 2, 'types' => 0]);
        });
        $this->assertSame('INSUFFICIENT_DATA', $insufficient->status->value);
        $this->generate([], null, $other)->assertStatus(409)->assertJsonPath('error.code', 'ROADMAP_NO_ACTIONABLE_GAPS');

        $this->assertSame(0, RoadmapSnapshot::query()->count());
    }

    /**
     * A skill gap snapshot whose stored specification does not match its
     * version is rejected, never reinterpreted.
     */
    public function test_an_invalid_skill_gap_snapshot_is_rejected(): void
    {
        $gaps = ChallengeFixtures::manyGaps($this->project);
        DB::table('skill_gap_snapshots')->where('id', $gaps->id)->update(['specification_fingerprint' => str_repeat('0', 64)]);

        $this->generate()->assertStatus(409)->assertJsonPath('error.code', 'ROADMAP_EVIDENCE_INVALID');

        DB::table('skill_gap_snapshots')->where('id', $gaps->id)->update(['skill_gap_version' => '9.9.9']);
        $this->generate()->assertStatus(409)->assertJsonPath('error.code', 'ROADMAP_EVIDENCE_INVALID');
        $this->assertSame(0, RoadmapSnapshot::query()->count());
    }

    public function test_steps_are_completed_in_dependency_order(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->generate()->assertCreated()->json('data.id');

        $this->complete($id, 'ch-find-errors')->assertStatus(409)->assertJsonPath('error.code', 'ROADMAP_STEP_PREREQUISITES_INCOMPLETE');
        $response = $this->complete($id, 'ch-syntax')->assertOk();
        $this->assertNotNull($response->json('data.tracks.0.steps.0.completed_at'));
        $this->assertSame(['completed' => 1, 'total' => 6], $response->json('data.progress'));
        $this->assertSame([false, true, false, false, false, false], array_column($response->json('data.tracks.0.steps'), 'can_complete'));

        $this->complete($id, 'ch-syntax')->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.progress.completed', 1);
        $this->assertSame(1, RoadmapStepCompletion::query()->count());
        $this->complete($id, 'ch-unknown-step')->assertNotFound();
        $this->complete($id, 'Not A Step')->assertNotFound();
    }

    /**
     * Completing every step completes the roadmap: learning progress only.
     */
    public function test_completing_every_step_completes_the_roadmap_and_changes_no_assessment(): void
    {
        $gaps = ChallengeFixtures::oneGap($this->project);
        $state = fn (): string => (string) json_encode([
            DB::table('skill_gap_snapshots')->where('id', $gaps->id)->first(),
            DB::table('skill_gap_results')->where('skill_gap_snapshot_id', $gaps->id)->orderBy('position')->get(),
            DB::table('competency_snapshots')->where('id', $gaps->competency_snapshot_id)->first(),
            DB::table('dna_snapshots')->where('id', $gaps->dna_snapshot_id)->first(),
            DB::table('analysis_runs')->where('id', $gaps->analysis_run_id)->first(),
        ]);
        $before = $state();
        $id = $this->generate()->assertCreated()->json('data.id');

        $done = $this->completeAll($id)->json('data');

        $this->assertSame('COMPLETED', $done['status']);
        $this->assertNotNull($done['completed_at']);
        $this->assertSame(['completed' => 6, 'total' => 6], $done['progress']);
        $this->assertSame($before, $state(), 'roadmap progress changes no gap, competency, DNA or run');
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/skill-gaps/{$gaps->id}")
            ->assertJsonPath('data.status', 'GAPS_IDENTIFIED')
            ->assertJsonPath('data.results.3.status', 'GAP')
            ->assertJsonPath('data.results.3.priority', 'HIGH');
        $this->assertSame(1, SkillGapSnapshot::query()->count());
        // A completed roadmap is final, and generating again returns it.
        $this->complete($id, 'ch-syntax')->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->generate()->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    }

    /**
     * A newer skill gap analysis gives a new roadmap; the old one is kept,
     * readable and SUPERSEDED, and accepts no further progress.
     */
    public function test_a_newer_analysis_supersedes_the_active_roadmap(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $old = $this->generate()->assertCreated()->json('data.id');
        $this->complete($old, 'ch-syntax')->assertOk();
        $this->travel(1)->minutes();
        ChallengeFixtures::manyGaps($this->project);

        $new = $this->generate()->assertCreated()->json('data');

        $this->assertNotSame($old, $new['id']);
        $previous = $this->show($old)->assertOk()->json('data');
        $this->assertSame(['SUPERSEDED', $new['id'], 1], [$previous['status'], $previous['superseded_by'], $previous['progress']['completed']]);
        $this->assertNotNull($previous['superseded_at']);
        $this->assertSame([false], array_unique(array_merge(...array_map(fn (array $t): array => array_column($t['steps'], 'can_complete'), $previous['tracks']))));
        $this->complete($old, 'ch-find-errors')->assertStatus(409)->assertJsonPath('error.code', 'ROADMAP_NOT_ACTIVE');
        $this->complete($old, 'ch-syntax')->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $list = $this->asUser($this->owner)->getJson($this->path())->assertOk();
        $this->assertSame([$new['id'], $old], array_column($list->json('data'), 'id'));
        $this->assertSame(['ACTIVE', 'SUPERSEDED'], array_column($list->json('data'), 'status'));
        $this->assertSame([['completed' => 0, 'total' => 21], ['completed' => 1, 'total' => 6]], array_column($list->json('data'), 'progress'));
    }

    /**
     * A newer analysis without actionable gaps leaves the existing roadmap as it is.
     */
    public function test_a_newer_analysis_without_gaps_keeps_the_roadmap(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->generate()->assertCreated()->json('data.id');
        $this->travel(1)->minutes();
        AssessmentFixtures::skillGaps($this->project, SkillGapApiTest::noGaps(...));

        $this->generate()->assertStatus(409)->assertJsonPath('error.code', 'ROADMAP_NO_ACTIONABLE_GAPS');

        $this->show($id)->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_archived_projects_keep_their_roadmaps_but_accept_nothing_new(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->generate()->assertCreated()->json('data.id');
        $this->project->archive();

        $this->show($id)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->asUser($this->owner)->getJson($this->path())->assertOk()->assertJsonPath('meta.total', 1);
        $this->complete($id, 'ch-syntax')->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
        $this->generate()->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
        $this->assertSame(0, RoadmapStepCompletion::query()->count());
    }

    public function test_only_the_owner_can_see_or_change_a_roadmap(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->generate()->assertCreated()->json('data.id');
        $stranger = User::factory()->create();
        $theirs = Project::factory()->for($stranger)->create();

        $this->asUser($stranger)->getJson($this->path())->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->show($id, $stranger)->assertNotFound();
        $this->generate([], $stranger)->assertNotFound();
        $this->complete($id, 'ch-syntax', $stranger)->assertNotFound();
        // A roadmap seen through another project is not found either.
        $this->asUser($stranger)->getJson($this->path("/{$id}", $theirs))->assertNotFound();
        $this->complete($id, 'ch-syntax', $stranger, $theirs)->assertNotFound();
        $this->asUser($this->owner)->getJson($this->path('/01k6p0a1b2c3d4e5f6g7h8j9zz'))->assertNotFound();
        $this->assertSame(0, RoadmapStepCompletion::query()->count());
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson($this->path())->assertUnauthorized();
        $this->postJson($this->path())->assertUnauthorized();
    }

    public function test_roadmaps_cannot_be_changed_or_deleted_through_the_api(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $id = $this->generate()->assertCreated()->json('data.id');

        foreach (['putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->asUser($this->owner)->{$method}($this->path("/{$id}"), ['status' => 'COMPLETED'])->assertStatus(405);
        }
        $this->asUser($this->owner)->getJson($this->path("/{$id}/steps/ch-syntax/complete"))->assertStatus(405);
        $this->asUser($this->owner)->postJson($this->path("/{$id}/steps/ch-syntax/complete"), ['completed_at' => '2020-01-01T00:00:00Z'])->assertStatus(422);
        $this->show($id)->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.progress.completed', 0);
    }

    /**
     * Responses carry what the page needs: no owner IDs, storage keys,
     * URLs or analyzer payloads.
     */
    public function test_responses_leak_nothing_internal(): void
    {
        ChallengeFixtures::manyGaps($this->project);
        $id = $this->generate()->json('data.id');

        foreach ([$this->show($id), $this->asUser($this->owner)->getJson($this->path())] as $response) {
            $body = (string) $response->getContent();
            $this->assertStringNotContainsString($this->owner->id, $body);
            $this->assertStringNotContainsString('user_id', $body);
            $this->assertDoesNotMatchRegularExpression('#storage_key|https?://|minio|analyzer|source\.zip|"ir"|"metrics"#i', $body);
        }
    }

    /**
     * The challenge step links to the practice challenge once one is assigned.
     */
    public function test_the_challenge_step_links_to_the_assigned_challenge(): void
    {
        config(['codedna.challenges.enabled' => true]);
        $gaps = ChallengeFixtures::oneGap($this->project);
        $id = $this->generate()->json('data.id');
        $challenge = app(AssignChallenge::class)->handle($this->project, $this->owner, $gaps->id, 'CODE_HYGIENE')->instance;

        $step = collect($this->show($id)->json('data.tracks.0.steps'))->firstWhere('type', 'CHALLENGE');

        $this->assertSame(['challenge_id' => $challenge->id, 'definition_key' => 'CODE_HYGIENE_001', 'status' => 'ASSIGNED'], $step['practice']);
        $this->assertSame('CODE_HYGIENE_001', $step['challenge']['key']);
        $this->assertSame([null], array_values(array_unique(array_column(array_filter($this->show($id)->json('data.tracks.0.steps'), fn (array $s): bool => $s['type'] !== 'CHALLENGE'), 'practice'))));
    }

    /**
     * Reads are bounded: the same number of queries whatever the number of steps.
     */
    public function test_reads_use_a_bounded_number_of_queries(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $small = $this->generate()->json('data.id');
        $other = Project::factory()->for($this->owner)->create();
        ChallengeFixtures::manyGaps($other);
        $large = $this->generate([], null, $other)->json('data.id');

        $count = function (string $path): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->asUser($this->owner)->getJson($path)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count($this->path("/{$small}")), $count("/api/v1/projects/{$other->id}/roadmaps/{$large}"));
        $this->assertLessThanOrEqual(10, $count($this->path("/{$small}")));
        $this->assertLessThanOrEqual(8, $count($this->path()));
    }

    /**
     * A stored roadmap never changes when the catalog changes; its detail
     * says whether the server still uses the catalog it was generated with.
     */
    public function test_a_changed_catalog_leaves_stored_roadmaps_unchanged_and_reports_it(): void
    {
        ChallengeFixtures::oneGap($this->project);
        $before = $this->generate()->assertCreated()->json('data');

        $directory = sys_get_temp_dir().'/roadmap-'.Str::random(8);
        mkdir($directory);
        try {
            foreach (glob(resource_path('roadmaps/v1/*.json')) ?: [] as $file) {
                $document = json_decode((string) file_get_contents($file), true);
                $document['steps'][0]['title'] .= ' (revised)';
                file_put_contents($directory.'/'.basename($file), json_encode($document));
            }
            $this->app->instance(RoadmapCatalog::class, new RoadmapCatalog('1.0.0', app(ChallengeCatalog::class), app(RoadmapRules::class), $directory));
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }

        $after = $this->show($before['id'])->assertOk()->json('data');

        $this->assertSame(['catalog' => false, 'rules' => true], $after['current']);
        $this->assertSame($before['tracks'][0]['steps'][0]['title'], $after['tracks'][0]['steps'][0]['title']);
        $this->assertSame($before['fingerprints'], $after['fingerprints']);
    }

    public function test_generation_and_progress_are_rate_limited(): void
    {
        ChallengeFixtures::oneGap($this->project);
        foreach (range(1, 10) as $i) {
            $this->generate();
        }
        $this->generate()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');

        $id = RoadmapSnapshot::query()->value('id');
        foreach (range(1, 60) as $i) {
            $this->complete((string) $id, 'ch-syntax');
        }
        $this->complete((string) $id, 'ch-syntax')->assertStatus(429);
    }
}
