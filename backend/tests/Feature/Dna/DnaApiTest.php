<?php

declare(strict_types=1);

namespace Tests\Feature\Dna;

use App\Actions\Dna\CalculateDnaSnapshot;
use App\Models\AnalysisRun;
use App\Models\DnaSnapshot;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use stdClass;
use Tests\Support\StoredResults;
use Tests\TestCase;

/**
 * GET /api/v1/projects/{project}/dna and .../dna/{snapshot} (Phase 12), over
 * snapshots created by the real scoring engine from stored results.
 */
final class DnaApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner)->create();
    }

    /**
     * @param  (callable(stdClass): void)|null  $mutate
     */
    private function score(?callable $mutate = null, ?Project $project = null): DnaSnapshot
    {
        $run = StoredResults::succeededRun('static_analysis', $mutate, SourceSnapshot::factory()->for($project ?? $this->project)->create());

        return app(CalculateDnaSnapshot::class)->handle($run->id)->snapshot;
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function fetch(string $path, ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->owner)->getJson($path);
    }

    private function listPath(?Project $project = null): string
    {
        return '/api/v1/projects/'.($project ?? $this->project)->id.'/dna';
    }

    public function test_the_owner_lists_their_projects_dna_newest_first(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');
        $older = $this->score(CalculateDnaSnapshotTest::rateable(...));
        Carbon::setTestNow('2026-10-11 10:00:00');
        $newer = $this->score();
        Carbon::setTestNow();

        $response = $this->fetch($this->listPath());

        $response->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 25, 'total' => 2, 'last_page' => 1])
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
        $this->assertSame([
            'id' => $older->id,
            'type' => 'dna_snapshot',
            'project_id' => $this->project->id,
            'analysis_run_id' => $older->analysis_run_id,
            'source_snapshot_id' => $older->source_snapshot_id,
            'source_snapshot_version' => $older->sourceSnapshot->version,
            'status' => 'READY',
            'overall_score' => '0.8050',
            'data_quality' => '0.9000',
            'scoring_version' => '1.0.0',
            'metrics_version' => '1.0',
            'created_at' => '2026-10-10T10:00:00Z',
        ], $response->json('data.1'));
        $this->assertSame(['INSUFFICIENT_DATA', null, '0.3841'], [
            $response->json('data.0.status'), $response->json('data.0.overall_score'), $response->json('data.0.data_quality'),
        ]);
    }

    public function test_ties_are_ordered_by_id_and_the_list_is_paginated(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->score()->id;
        }
        Carbon::setTestNow();
        rsort($ids);

        $this->fetch($this->listPath().'?per_page=2')->assertOk()
            ->assertJsonPath('data.0.id', $ids[0])
            ->assertJsonPath('data.1.id', $ids[1])
            ->assertJsonPath('meta.last_page', 2);
        $this->fetch($this->listPath().'?per_page=2&page=2')->assertOk()
            ->assertJsonPath('data.0.id', $ids[2])
            ->assertJsonCount(1, 'data');
        $this->fetch($this->listPath().'?per_page=500')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_an_empty_project_has_an_empty_list(): void
    {
        $this->fetch($this->listPath())->assertOk()->assertExactJson([
            'data' => [],
            'meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1],
        ]);
    }

    public function test_the_detail_returns_the_stored_snapshot_exactly(): void
    {
        $dna = $this->score(CalculateDnaSnapshotTest::rateable(...));
        $run = AnalysisRun::query()->findOrFail($dna->analysis_run_id);

        $response = $this->fetch("{$this->listPath()}/{$dna->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame([
            'id', 'type', 'project_id', 'analysis_run_id', 'source_snapshot_id', 'status', 'overall_score', 'data_quality',
            'scoring_version', 'specification_fingerprint', 'versions', 'result_hash', 'created_at', 'source_snapshot',
            'analysis_run', 'dimensions', 'aggregation', 'availability', 'data_quality_breakdown',
        ], array_keys($data));
        $this->assertSame(['READY', '0.8050', '0.9000', '1.0.0'], [$data['status'], $data['overall_score'], $data['data_quality'], $data['scoring_version']]);
        $this->assertSame($dna->evidence['specification_fingerprint'], $data['specification_fingerprint']);
        $this->assertSame(['scoring' => '1.0.0', 'metrics' => '1.0', 'analyzer' => '0.2.0', 'ir' => '1.1', 'contract' => '1.0'], $data['versions']);
        $this->assertSame($run->result_hash, $data['result_hash']);
        $this->assertSame(['id', 'version', 'file_count', 'primary_language', 'created_at'], array_keys($data['source_snapshot']));
        $this->assertSame($dna->source_snapshot_id, $data['source_snapshot']['id']);
        $this->assertSame(['id' => $run->id, 'result_type' => 'static_analysis', 'status' => 'SUCCEEDED', 'completed_at' => $run->completed_at?->toIso8601ZuluString()], $data['analysis_run']);

        // Dimensions: specification order, values exactly as stored.
        $this->assertSame(['COMPLEXITY', 'STRUCTURE', 'CODE_HYGIENE'], array_column($data['dimensions'], 'dimension'));
        $complexity = $data['dimensions'][0];
        $this->assertSame([
            'dimension' => 'COMPLEXITY',
            'name' => 'Complexity',
            'description' => 'How much branching the functions contain, from cyclomatic complexity and nesting.',
            'status' => 'SCORED',
            'unavailable_reason' => null,
            'score' => '0.8125',
            'weight' => '0.4000',
            'effective_weight' => '0.4000',
            'contribution' => '0.3250',
            'data_quality' => '0.9000',
        ], array_diff_key($complexity, ['components' => true]));
        $this->assertSame(['mean_cyclomatic_complexity', 'complex_function_share', 'deep_nesting_share'], array_column($complexity['components'], 'key'));
        $this->assertSame([
            'key' => 'mean_cyclomatic_complexity',
            'description' => 'complexity_total / functions_total: average cyclomatic complexity per function (1 is the minimum).',
            'share' => false,
            'status' => 'AVAILABLE',
            'required' => true,
            'weight' => '0.5000',
            'value' => '4.0000',
            'score' => '0.7500',
            'best' => '2.0000',
            'worst' => '10.0000',
            'minimum_denominator' => 5,
            'numerator' => [['metric' => 'metrics.overall.complexity_total', 'value' => 160]],
            'denominator' => [['metric' => 'metrics.overall.functions_total', 'value' => 40]],
        ], $complexity['components'][0]);
        $this->assertSame(
            [['metric' => 'metrics.overall.files_parsed', 'value' => 9], ['metric' => 'metrics.overall.files_parse_error', 'value' => 1]],
            $data['dimensions'][2]['components'][0]['denominator'],
        );
        foreach ($data['dimensions'] as $dimension) {
            $this->assertEquals($dna->dimensions[$dimension['dimension']]['score'], $dimension['score']);
            $this->assertEquals($dna->dimensions[$dimension['dimension']]['contribution'], $dimension['contribution']);
        }

        $this->assertSame(['method' => 'weighted_mean_of_scored_dimensions', 'minimum_scored_dimensions' => 2,
            'scored_dimensions' => ['COMPLEXITY', 'STRUCTURE', 'CODE_HYGIENE'], 'unavailable_dimensions' => [],
            'scored_weight' => '1.0000', 'renormalized' => false], $data['aggregation']);
        $this->assertSame(['AVAILABLE' => 7, 'INSUFFICIENT_EVIDENCE' => 0, 'UNSUPPORTED' => 0, 'MISSING' => 0], $data['availability']);
        $this->assertSame([
            'parse_coverage' => ['value' => '0.9000', 'weight' => '0.5000', 'files_parsed' => 9, 'files_analyzable' => 10],
            'evidence_volume' => ['value' => '0.8000', 'weight' => '0.2500', 'functions' => 40, 'target' => 50],
            'metric_availability' => ['value' => '1.0000', 'weight' => '0.2500', 'available_components' => 7, 'components' => 7],
        ], $data['data_quality_breakdown']);
    }

    public function test_scores_keep_their_exact_decimal_representation(): void
    {
        // STRUCTURE 0.7857 and overall 0.8343: four significant decimals survive the API unchanged.
        $dna = $this->score(function (stdClass $result): void {
            StoredResults::set($result, [
                'files_analyzable' => 10, 'files_parsed' => 9, 'files_parse_error' => 1,
                'functions_total' => 7, 'complexity_total' => 14, 'complexity_over_threshold' => 0, 'types' => 10,
            ], ['structure/parameter-count' => 1]);
        });

        $body = (string) $this->fetch("{$this->listPath()}/{$dna->id}")->getContent();

        $this->assertStringContainsString('"overall_score":"0.8343"', $body);
        $this->assertStringContainsString('"score":"0.7857"', $body);
        $this->assertStringContainsString('"score":"0.2857"', $body);
        $this->assertSame('0.8343', DnaSnapshot::query()->findOrFail($dna->id)->overall_score);
    }

    public function test_insufficient_data_and_unavailable_evidence_are_returned_as_null_never_zero(): void
    {
        $insufficient = $this->score();
        $missing = $this->score(function (stdClass $result): void {
            CalculateDnaSnapshotTest::rateable($result);
            unset($result->findings->by_rule->{'structure/nesting-depth'});
        });

        $data = $this->fetch("{$this->listPath()}/{$insufficient->id}")->assertOk()->json('data');
        $this->assertSame('INSUFFICIENT_DATA', $data['status']);
        $this->assertNull($data['overall_score']);
        $this->assertSame('0.3841', $data['data_quality']);
        $complexity = $data['dimensions'][0];
        $this->assertSame(['UNAVAILABLE', 'INSUFFICIENT_EVIDENCE', null, null, null], [
            $complexity['status'], $complexity['unavailable_reason'], $complexity['score'], $complexity['effective_weight'], $complexity['contribution'],
        ]);
        $this->assertSame(['INSUFFICIENT_EVIDENCE', null, null], [
            $complexity['components'][0]['status'], $complexity['components'][0]['value'], $complexity['components'][0]['score'],
        ]);
        $this->assertSame([['metric' => 'metrics.overall.functions_total', 'value' => 3]], $complexity['components'][0]['denominator']);
        $this->assertSame(['AVAILABLE' => 1, 'INSUFFICIENT_EVIDENCE' => 6, 'UNSUPPORTED' => 0, 'MISSING' => 0], $data['availability']);

        $data = $this->fetch("{$this->listPath()}/{$missing->id}")->assertOk()->json('data');
        $this->assertSame(['READY', 'UNAVAILABLE', 'MISSING'], [$data['status'], $data['dimensions'][0]['status'], $data['dimensions'][0]['unavailable_reason']]);
        $nesting = $data['dimensions'][0]['components'][2];
        $this->assertSame(['deep_nesting_share', 'MISSING'], [$nesting['key'], $nesting['status']]);
        $this->assertSame([['metric' => 'findings.by_rule.structure/nesting-depth', 'value' => null]], $nesting['numerator']);
        $this->assertTrue($data['aggregation']['renormalized']);
        $this->assertSame(['COMPLEXITY'], $data['aggregation']['unavailable_dimensions']);
    }

    public function test_other_users_missing_projects_and_foreign_snapshots_are_all_404(): void
    {
        $dna = $this->score(CalculateDnaSnapshotTest::rateable(...));
        $stranger = User::factory()->create();
        $otherProject = Project::factory()->for($this->owner)->create();
        $foreign = $this->score(null, Project::factory()->for($stranger)->create());

        $notFound = [
            [$this->listPath(), $stranger],
            ["{$this->listPath()}/{$dna->id}", $stranger],
            ['/api/v1/projects/'.strtolower((string) Str::ulid()).'/dna', $this->owner],
            ["{$this->listPath()}/".strtolower((string) Str::ulid()), $this->owner],
            ["{$this->listPath($otherProject)}/{$dna->id}", $this->owner],
            ["{$this->listPath()}/{$foreign->id}", $this->owner],
            ["{$this->listPath($foreign->project)}/{$foreign->id}", $this->owner],
        ];
        foreach ($notFound as [$path, $user]) {
            $this->fetch($path, $user)->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        }
        $this->fetch("{$this->listPath()}/not-a-ulid")->assertStatus(404);
        $this->fetch($this->listPath($otherProject))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guests_are_rejected(): void
    {
        $dna = $this->score();

        $this->getJson($this->listPath())->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->getJson("{$this->listPath()}/{$dna->id}")->assertStatus(401);
    }

    public function test_archived_projects_keep_their_dna_readable(): void
    {
        $dna = $this->score(CalculateDnaSnapshotTest::rateable(...));
        $this->project->archive();

        $this->fetch($this->listPath())->assertOk()->assertJsonPath('data.0.id', $dna->id);
        $this->fetch("{$this->listPath()}/{$dna->id}")->assertOk()->assertJsonPath('data.overall_score', '0.8050');
    }

    public function test_dna_is_read_only(): void
    {
        $dna = $this->score(CalculateDnaSnapshotTest::rateable(...));
        $before = DB::table('dna_snapshots')->where('id', $dna->id)->first();

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->asUser($this->owner)->{$method}($this->listPath(), ['overall_score' => '1.0000'])->assertStatus(405);
            $this->asUser($this->owner)->{$method}("{$this->listPath()}/{$dna->id}", ['overall_score' => '1.0000'])->assertStatus(405);
        }
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_contains($route->uri(), '/dna')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri());
            }
        }
        $this->assertEquals($before, DB::table('dna_snapshots')->where('id', $dna->id)->first());
        $this->assertSame(1, DnaSnapshot::query()->count());
    }

    public function test_responses_expose_no_storage_details_urls_or_secrets(): void
    {
        $dna = $this->score(CalculateDnaSnapshotTest::rateable(...));
        $source = SourceSnapshot::query()->findOrFail($dna->source_snapshot_id);

        $bodies = [
            (string) $this->fetch($this->listPath())->getContent(),
            (string) $this->fetch("{$this->listPath()}/{$dna->id}")->getContent(),
        ];
        foreach ($bodies as $body) {
            foreach ([$source->storage_key, 'storage_key', 'storage_disk', 'X-Amz', 'http://', 'https://', 'analyzer:8000', 'minio',
                'user_id', $this->owner->id, $this->owner->email, 'hmac', 'request_id', 'by_language', 'findings":{', 'items'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body);
            }
        }
    }

    public function test_the_list_does_not_grow_queries_with_the_number_of_snapshots(): void
    {
        $this->score();
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->fetch($this->listPath())->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $one = $queries();
        for ($i = 0; $i < 4; $i++) {
            $this->score();
        }

        $this->assertSame($one, $queries());
        // The list never reads the dimensions and evidence documents.
        DB::enableQueryLog();
        $this->fetch($this->listPath())->assertOk();
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        $this->assertStringNotContainsString('"dimensions"', $sql);
        $this->assertStringNotContainsString('"evidence"', $sql);
        $this->assertStringNotContainsString('analysis_results', $sql);
    }
}
