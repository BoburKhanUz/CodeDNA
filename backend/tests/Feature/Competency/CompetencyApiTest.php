<?php

declare(strict_types=1);

namespace Tests\Feature\Competency;

use App\Actions\Competency\CalculateCompetencyMatrix;
use App\Actions\Dna\CalculateDnaSnapshot;
use App\Models\CompetencySnapshot;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Competency\CompetencySpecification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use stdClass;
use Tests\Feature\Dna\CalculateDnaSnapshotTest;
use Tests\Support\StoredResults;
use Tests\TestCase;

/**
 * GET /api/v1/projects/{project}/competencies and .../competencies/{snapshot}
 * (Phase 13), over snapshots created by the real scoring and competency
 * engines from stored results.
 */
final class CompetencyApiTest extends TestCase
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
    private function assess(?callable $mutate = null, ?Project $project = null): CompetencySnapshot
    {
        $run = StoredResults::succeededRun('static_analysis', $mutate, SourceSnapshot::factory()->for($project ?? $this->project)->create());
        $dna = app(CalculateDnaSnapshot::class)->handle($run->id)->snapshot;

        return app(CalculateCompetencyMatrix::class)->handle($dna->id)->snapshot;
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
        return '/api/v1/projects/'.($project ?? $this->project)->id.'/competencies';
    }

    public function test_the_owner_lists_competency_snapshots_newest_first(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');
        $older = $this->assess(CalculateDnaSnapshotTest::rateable(...));
        Carbon::setTestNow('2026-10-11 10:00:00');
        $newer = $this->assess();
        Carbon::setTestNow();

        $response = $this->fetch($this->listPath());

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
        $this->assertSame([
            'id' => $older->id,
            'type' => 'competency_snapshot',
            'project_id' => $this->project->id,
            'dna_snapshot_id' => $older->dna_snapshot_id,
            'analysis_run_id' => $older->analysis_run_id,
            'source_snapshot_id' => $older->source_snapshot_id,
            'status' => 'ASSESSED',
            'competency_version' => '1.0.0',
            'dna_scoring_version' => '1.0.0',
            'summary' => [
                'competencies' => 4,
                'statuses' => ['ASSESSED' => 4, 'INSUFFICIENT_EVIDENCE' => 0, 'UNSUPPORTED' => 0, 'MISSING' => 0],
                'levels' => ['NOT_ESTABLISHED' => 0, 'DEVELOPING' => 1, 'ESTABLISHED' => 1, 'STRONG' => 2],
            ],
            'created_at' => '2026-10-10T10:00:00Z',
        ], $response->json('data.1'));
    }

    public function test_ties_are_ordered_by_id_and_the_list_is_paginated(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');
        $ids = [$this->assess()->id, $this->assess()->id, $this->assess()->id];
        Carbon::setTestNow();
        rsort($ids);

        $this->fetch($this->listPath().'?per_page=2')->assertOk()
            ->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.1.id', $ids[1])->assertJsonPath('meta.last_page', 2);
        $this->fetch($this->listPath().'?per_page=2&page=2')->assertOk()->assertJsonPath('data.0.id', $ids[2]);
        $this->fetch($this->listPath().'?per_page=101')->assertStatus(422);
    }

    public function test_a_project_without_competencies_has_an_empty_list(): void
    {
        $this->fetch($this->listPath())->assertOk()->assertExactJson([
            'data' => [],
            'meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1],
        ]);
    }

    public function test_the_detail_returns_the_stored_matrix_with_its_provenance(): void
    {
        $snapshot = $this->assess(CalculateDnaSnapshotTest::rateable(...));

        $data = $this->fetch("{$this->listPath()}/{$snapshot->id}")->assertOk()->json('data');

        $this->assertSame([
            'id', 'type', 'project_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'status', 'competency_version',
            'specification_fingerprint', 'dna_scoring_version', 'created_at', 'levels', 'summary', 'languages', 'dna_snapshot',
            'source_snapshot', 'analysis_run', 'competencies',
        ], array_keys($data));
        $this->assertSame(CompetencySpecification::v1_0_0()->fingerprint(), $data['specification_fingerprint']);
        $this->assertSame(['javascript', 'php', 'python'], $data['languages']);
        $this->assertSame([
            ['level' => 'NOT_ESTABLISHED', 'name' => 'Not established', 'ordinal' => 0, 'minimum_score' => '0.0000'],
            ['level' => 'DEVELOPING', 'name' => 'Developing', 'ordinal' => 1, 'minimum_score' => '0.4000'],
            ['level' => 'ESTABLISHED', 'name' => 'Established', 'ordinal' => 2, 'minimum_score' => '0.6500'],
            ['level' => 'STRONG', 'name' => 'Strong', 'ordinal' => 3, 'minimum_score' => '0.8500'],
        ], $data['levels']);
        $this->assertSame(['id', 'status', 'overall_score', 'data_quality', 'scoring_version', 'specification_fingerprint', 'created_at'], array_keys($data['dna_snapshot']));
        $this->assertSame([$snapshot->dna_snapshot_id, 'READY', '0.8050', '0.9000', '1.0.0'], [
            $data['dna_snapshot']['id'], $data['dna_snapshot']['status'], $data['dna_snapshot']['overall_score'],
            $data['dna_snapshot']['data_quality'], $data['dna_snapshot']['scoring_version'],
        ]);
        $this->assertSame($snapshot->source_snapshot_id, $data['source_snapshot']['id']);
        $this->assertSame([$snapshot->analysis_run_id, 'SUCCEEDED'], [$data['analysis_run']['id'], $data['analysis_run']['status']]);

        $this->assertSame(['COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE', 'CODE_HYGIENE'], array_column($data['competencies'], 'key'));
        $complexity = $data['competencies'][0];
        $this->assertSame([
            'key' => 'COMPLEXITY_MANAGEMENT',
            'name' => 'Complexity management',
            'description' => 'Keeping decision logic per function small: how much branching (cyclomatic complexity) functions contain.',
            'status' => 'ASSESSED',
            'score' => '0.7500',
            'level' => 'ESTABLISHED',
            'evidence_quality' => '0.9000',
            'evidence_quality_terms' => ['parse_coverage' => '0.9000', 'evidence_volume' => '0.8000', 'evidence_availability' => '1.0000'],
            'limitations' => [],
        ], array_diff_key($complexity, ['evidence' => true]));
        $this->assertSame([
            'source' => 'COMPLEXITY.mean_cyclomatic_complexity',
            'dimension' => 'COMPLEXITY',
            'component' => 'mean_cyclomatic_complexity',
            'rationale' => 'Average branching per function: the central measure of decision logic.',
            'share' => false,
            'weight' => '0.6000',
            'required' => true,
            'status' => 'AVAILABLE',
            'value' => '4.0000',
            'score' => '0.7500',
            'best' => '2.0000',
            'worst' => '10.0000',
            'minimum_denominator' => 5,
            'numerator' => [['metric' => 'metrics.overall.complexity_total', 'value' => 160]],
            'denominator' => [['metric' => 'metrics.overall.functions_total', 'value' => 40]],
        ], $complexity['evidence'][0]);
        $this->assertSame(['0.7500', '0.9000', '1.0000', '0.6000'], array_column($data['competencies'], 'score'));
    }

    public function test_scores_keep_their_exact_decimal_representation(): void
    {
        // FUNCTION_DESIGN: 1 × 0.4 + 0.2857 × 0.3 + 1 × 0.3 = 0.78571 -> 0.7857
        $snapshot = $this->assess(function (stdClass $result): void {
            StoredResults::set($result, [
                'files_analyzable' => 10, 'files_parsed' => 10, 'files_parse_error' => 0,
                'functions_total' => 7, 'complexity_total' => 14, 'complexity_over_threshold' => 0, 'types' => 10,
            ], ['structure/parameter-count' => 1]);
        });

        $body = (string) $this->fetch("{$this->listPath()}/{$snapshot->id}")->getContent();

        $this->assertStringContainsString('"key":"FUNCTION_DESIGN","name":"Function design"', $body);
        $this->assertStringContainsString('"score":"0.7857","level":"ESTABLISHED"', $body);
        $this->assertStringContainsString('"score":"0.2857"', $body);
    }

    public function test_insufficient_and_unavailable_evidence_is_returned_as_persisted(): void
    {
        $insufficient = $this->assess();
        $data = $this->fetch("{$this->listPath()}/{$insufficient->id}")->assertOk()->json('data');

        $this->assertSame('ASSESSED', $data['status']);
        $matrix = array_column($data['competencies'], null, 'key');
        foreach (['COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE'] as $key) {
            $this->assertSame(['INSUFFICIENT_EVIDENCE', null, null], [$matrix[$key]['status'], $matrix[$key]['score'], $matrix[$key]['level']]);
        }
        $this->assertSame('INSUFFICIENT_EVIDENCE', $matrix['TYPE_STRUCTURE']['evidence'][0]['status']);
        $this->assertSame([['metric' => 'metrics.overall.types', 'value' => 1]], $matrix['TYPE_STRUCTURE']['evidence'][0]['denominator']);
        $this->assertSame(['0.0000', 'NOT_ESTABLISHED'], [$matrix['CODE_HYGIENE']['score'], $matrix['CODE_HYGIENE']['level']]);

        $missing = $this->assess(function (stdClass $result): void {
            CalculateDnaSnapshotTest::rateable($result);
            unset($result->findings->by_rule->{'structure/nesting-depth'});
        });
        $matrix = array_column($this->fetch("{$this->listPath()}/{$missing->id}")->json('data.competencies'), null, 'key');
        $this->assertSame(['MISSING', null], [$matrix['FUNCTION_DESIGN']['status'], $matrix['FUNCTION_DESIGN']['score']]);
        $this->assertSame([['metric' => 'findings.by_rule.structure/nesting-depth', 'value' => null]], $matrix['FUNCTION_DESIGN']['evidence'][2]['numerator']);
        $this->assertSame('ASSESSED', $matrix['COMPLEXITY_MANAGEMENT']['status']);
    }

    public function test_other_users_missing_projects_and_foreign_snapshots_are_all_404(): void
    {
        $snapshot = $this->assess(CalculateDnaSnapshotTest::rateable(...));
        $stranger = User::factory()->create();
        $otherProject = Project::factory()->for($this->owner)->create();
        $foreign = $this->assess(null, Project::factory()->for($stranger)->create());

        foreach ([
            [$this->listPath(), $stranger],
            ["{$this->listPath()}/{$snapshot->id}", $stranger],
            ['/api/v1/projects/'.strtolower((string) Str::ulid()).'/competencies', $this->owner],
            ["{$this->listPath()}/".strtolower((string) Str::ulid()), $this->owner],
            ["{$this->listPath($otherProject)}/{$snapshot->id}", $this->owner],
            ["{$this->listPath()}/{$foreign->id}", $this->owner],
            ['/api/v1/projects/'.$foreign->project_id."/competencies/{$foreign->id}", $this->owner],
        ] as [$path, $user]) {
            $this->fetch($path, $user)->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        }
        $this->fetch("{$this->listPath()}/not-a-ulid")->assertStatus(404);
    }

    public function test_guests_are_rejected(): void
    {
        $snapshot = $this->assess();

        $this->getJson($this->listPath())->assertStatus(401);
        $this->getJson("{$this->listPath()}/{$snapshot->id}")->assertStatus(401);
    }

    public function test_archived_projects_stay_readable(): void
    {
        $snapshot = $this->assess(CalculateDnaSnapshotTest::rateable(...));
        $this->project->archive();

        $this->fetch("{$this->listPath()}/{$snapshot->id}")->assertOk()->assertJsonPath('data.competencies.0.score', '0.7500');
    }

    public function test_competencies_are_read_only(): void
    {
        $snapshot = $this->assess(CalculateDnaSnapshotTest::rateable(...));
        $before = DB::table('competency_snapshots')->where('id', $snapshot->id)->first();

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $payload = ['score' => '1.0000', 'level' => 'STRONG', 'competency_version' => '9.9.9'];
            $this->asUser($this->owner)->{$method}($this->listPath(), $payload)->assertStatus(405);
            $this->asUser($this->owner)->{$method}("{$this->listPath()}/{$snapshot->id}", $payload)->assertStatus(405);
        }
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_contains($route->uri(), 'competenc')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri());
            }
        }
        $this->assertEquals($before, DB::table('competency_snapshots')->where('id', $snapshot->id)->first());
    }

    public function test_responses_expose_no_storage_details_urls_secrets_or_analyzer_payload(): void
    {
        $snapshot = $this->assess(CalculateDnaSnapshotTest::rateable(...));
        $source = SourceSnapshot::query()->findOrFail($snapshot->source_snapshot_id);

        foreach ([$this->fetch($this->listPath())->getContent(), $this->fetch("{$this->listPath()}/{$snapshot->id}")->getContent()] as $body) {
            foreach ([$source->storage_key, 'storage_key', 'storage_disk', 'X-Amz', 'http://', 'https://', 'analyzer:8000', 'minio',
                'user_id', $this->owner->id, $this->owner->email, 'hmac', 'request_id', 'by_language', '"findings":', '"items"', '"ir"'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, (string) $body);
            }
        }
    }

    public function test_queries_do_not_grow_with_the_number_of_snapshots(): void
    {
        $this->assess();
        $count = function (string $path): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->fetch($path)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $one = $count($this->listPath());
        $latest = $this->assess();
        for ($i = 0; $i < 3; $i++) {
            $this->assess();
        }

        $this->assertSame($one, $count($this->listPath()));
        DB::enableQueryLog();
        $this->fetch($this->listPath())->assertOk();
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        $this->assertStringNotContainsString('"competencies"', $sql);
        $this->assertStringNotContainsString('analysis_results', $sql);
        DB::flushQueryLog();
        $this->fetch("{$this->listPath()}/{$latest->id}")->assertOk();
        $this->assertStringNotContainsString('analysis_results', implode("\n", array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();
    }
}
