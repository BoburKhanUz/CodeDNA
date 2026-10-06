<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Jobs\AnalyzeSourceSnapshot;
use App\Models\AnalysisRun;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAnalyzer;
use Tests\TestCase;

/**
 * /api/v1/projects/{project}/analyses (Phase 10).
 */
final class AnalysisApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private SourceSnapshot $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAnalyzer::configure();
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
        $this->snapshot = SourceSnapshot::factory()->for($this->project)->create();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function start(array $body = [], ?User $as = null, ?Project $project = null): TestResponse
    {
        $project ??= $this->project;

        return $this->asUser($as ?? $this->owner)->postJson("/api/v1/projects/{$project->id}/analyses", $body + ['source_snapshot_id' => $this->snapshot->id]);
    }

    public function test_starting_an_analysis_creates_a_queued_run_and_dispatches_one_job(): void
    {
        $response = $this->start();

        $response->assertStatus(202)->assertHeaderMissing('Idempotent-Replayed');
        $id = $response->json('data.id');
        $this->assertSame([
            'id' => $id,
            'type' => 'analysis_run',
            'project_id' => $this->project->id,
            'source_snapshot_id' => $this->snapshot->id,
            'result_type' => 'foundation',
            'status' => 'QUEUED',
            'created_at' => $response->json('data.created_at'),
            'started_at' => null,
            'completed_at' => null,
            'failure' => null,
            'result' => null,
        ], $response->json('data'));
        Queue::assertPushedOn('analysis', AnalyzeSourceSnapshot::class, fn (AnalyzeSourceSnapshot $job) => $job->analysisRunId === $id);
        Queue::assertPushed(AnalyzeSourceSnapshot::class, 1);
        $this->assertSame($id, AnalysisRun::query()->findOrFail($id)->idempotency_key);
    }

    public function test_static_analysis_is_opt_in_and_result_types_are_validated(): void
    {
        $this->start(['result_type' => 'static_analysis'])->assertStatus(202)->assertJsonPath('data.result_type', 'static_analysis');

        foreach (['full', 'dna', 'FOUNDATION', '', 1, ['foundation']] as $invalid) {
            $this->start(['result_type' => $invalid])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonValidationErrors(['result_type'], 'error.details.fields');
        }
    }

    public function test_repeating_a_request_returns_the_equivalent_run_instead_of_a_new_one(): void
    {
        $first = $this->start()->json('data.id');

        $again = $this->start();
        $again->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first);
        $this->start(['result_type' => 'foundation'])->assertStatus(200)->assertJsonPath('data.id', $first);

        Queue::assertPushed(AnalyzeSourceSnapshot::class, 1);
        $this->assertSame(1, AnalysisRun::query()->count());
    }

    public function test_result_types_and_snapshots_are_independent_logical_analyses(): void
    {
        $foundation = $this->start()->json('data.id');
        $static = $this->start(['result_type' => 'static_analysis'])->json('data.id');
        $other = SourceSnapshot::factory()->for($this->project)->create();
        $otherSnapshot = $this->start(['source_snapshot_id' => $other->id])->json('data.id');

        $this->assertCount(3, array_unique([$foundation, $static, $otherSnapshot]));
        Queue::assertPushed(AnalyzeSourceSnapshot::class, 3);
    }

    public function test_a_succeeded_run_is_returned_and_a_failed_one_is_followed_by_a_new_run(): void
    {
        $succeeded = AnalysisRun::factory()->for($this->snapshot)->succeeded()->create();
        $this->start()->assertStatus(200)->assertJsonPath('data.id', $succeeded->id)->assertJsonPath('data.status', 'SUCCEEDED');

        $failed = AnalysisRun::factory()->for($this->snapshot)->failed('ANALYZER_UNAVAILABLE')->create(['result_type' => 'static_analysis']);
        $retry = $this->start(['result_type' => 'static_analysis']);
        $retry->assertStatus(202);
        $this->assertNotSame($failed->id, $retry->json('data.id'));
        $this->assertSame('FAILED', $failed->refresh()->status->value, 'the failed run stays as history');
    }

    public function test_only_the_owner_can_analyze_and_only_their_own_snapshots(): void
    {
        $stranger = User::factory()->create();
        $this->start([], $stranger)->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');

        // Another user's snapshot, or a snapshot of another of my projects, is just "invalid".
        $foreign = SourceSnapshot::factory()->create();
        $mine = SourceSnapshot::factory()->for(Project::factory()->for($this->owner))->create();
        foreach ([$foreign->id, $mine->id, strtolower((string) Str::ulid()), 'not-a-ulid', '../../etc'] as $snapshotId) {
            $this->start(['source_snapshot_id' => $snapshotId])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['source_snapshot_id'], 'error.details.fields');
        }
        $this->asUser($this->owner)->postJson("/api/v1/projects/{$this->project->id}/analyses", [])->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame(0, AnalysisRun::query()->count());
    }

    public function test_archived_projects_cannot_start_analyses(): void
    {
        $this->project->archive();

        $this->start()->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_ARCHIVED');
        Queue::assertNothingPushed();
    }

    public function test_guests_are_rejected(): void
    {
        $this->postJson("/api/v1/projects/{$this->project->id}/analyses", ['source_snapshot_id' => $this->snapshot->id])
            ->assertStatus(401);
    }

    public function test_clients_cannot_supply_urls_storage_keys_or_analyzer_options(): void
    {
        $response = $this->start([
            'url' => 'http://169.254.169.254/latest/meta-data/',
            'source' => ['url' => 'http://evil.example/x.zip'],
            'storage_key' => '../other/source.zip',
            'analyzer_url' => 'http://evil.example',
            'options' => ['languages' => ['php']],
        ]);

        $response->assertStatus(202);
        $run = AnalysisRun::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->snapshot->id, $run->source_snapshot_id);
        $this->assertStringNotContainsString('evil', (string) json_encode($run->metadata));
        $this->assertStringNotContainsString('169.254', (string) json_encode($run->metadata));
    }

    public function test_show_returns_status_failure_and_result_metadata_without_internals(): void
    {
        $queued = $this->start()->json('data.id');
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/analyses/{$queued}")
            ->assertOk()->assertJsonPath('data.status', 'QUEUED');

        $failed = AnalysisRun::factory()->for($this->snapshot)->failed('INVALID_ARCHIVE')->create(['result_type' => 'static_analysis']);
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/analyses/{$failed->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.failure', ['code' => 'INVALID_ARCHIVE', 'message' => 'The source archive is corrupt or contains unsafe entries.']);

        $body = $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/analyses/{$failed->id}")->getContent();
        foreach (['metadata', 'idempotency_key', 'storage_key', 'X-Amz', 'analyzer:8000', 'lease', 'Archive exceeds'] as $internal) {
            $this->assertStringNotContainsString($internal, (string) $body);
        }
    }

    public function test_runs_are_only_visible_through_their_owners_project(): void
    {
        $run = AnalysisRun::factory()->for($this->snapshot)->create();
        $otherProject = Project::factory()->for($this->owner)->create();
        $stranger = User::factory()->create();

        $this->asUser($this->owner)->getJson("/api/v1/projects/{$otherProject->id}/analyses/{$run->id}")->assertStatus(404);
        $this->asUser($stranger)->getJson("/api/v1/projects/{$this->project->id}/analyses/{$run->id}")->assertStatus(404);
        $this->asUser($stranger)->getJson("/api/v1/projects/{$this->project->id}/analyses")->assertStatus(404);
        $this->asUser($stranger)->getJson("/api/v1/projects/{$this->project->id}/analyses/{$run->id}/result")->assertStatus(404);
    }

    public function test_the_verified_result_of_a_succeeded_run_can_be_read(): void
    {
        $id = $this->start(['result_type' => 'static_analysis'])->json('data.id');
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/analyses/{$id}/result")
            ->assertStatus(409)->assertJsonPath('error.code', 'ANALYSIS_NOT_COMPLETED');

        Http::fake(['http://analyzer:8000/*' => fn (Request $r) => FakeAnalyzer::success($r)]);
        app()->call([new AnalyzeSourceSnapshot($id), 'handle']);

        $run = $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/analyses/{$id}");
        $run->assertOk()->assertJsonPath('data.status', 'SUCCEEDED')
            ->assertJsonPath('data.result.versions', ['contract' => '1.0', 'analyzer' => '0.2.0', 'ir' => '1.1', 'metrics' => '1.0']);

        $result = $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/analyses/{$id}/result");
        $result->assertOk()
            ->assertJsonPath('data.analysis_run_id', $id)
            ->assertJsonPath('data.result_type', 'static_analysis')
            ->assertJsonPath('data.result_hash', $run->json('data.result.result_hash'))
            ->assertJsonPath('data.result.result_type', 'static_analysis')
            ->assertJsonPath('data.result.metrics.version', '1.0')
            ->assertJsonPath('data.result.ir.version', '1.1');
    }

    public function test_the_run_list_is_paginated_newest_first(): void
    {
        $older = AnalysisRun::factory()->for($this->snapshot)->succeeded()->create(['created_at' => now()->subDay()]);
        $newer = $this->start(['result_type' => 'static_analysis'])->json('data.id');

        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/analyses?per_page=1")
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer)
            ->assertJsonPath('meta.total', 2);
        $this->asUser($this->owner)->getJson("/api/v1/projects/{$this->project->id}/analyses?page=2&per_page=1")
            ->assertJsonPath('data.0.id', $older->id);
    }
}
