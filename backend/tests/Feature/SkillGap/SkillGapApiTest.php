<?php

declare(strict_types=1);

namespace Tests\Feature\SkillGap;

use App\Actions\Competency\CalculateCompetencyMatrix;
use App\Actions\Dna\CalculateDnaSnapshot;
use App\Actions\SkillGap\CalculateSkillGapSnapshot;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\SkillGap\SkillGapSpecification;
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
 * GET /api/v1/projects/{project}/skill-gaps and .../skill-gaps/{snapshot}
 * (Phase 14), over snapshots created by the real engines from stored results.
 */
final class SkillGapApiTest extends TestCase
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
    private function analyze(?callable $mutate = null, ?Project $project = null): SkillGapSnapshot
    {
        $run = StoredResults::succeededRun('static_analysis', $mutate, SourceSnapshot::factory()->for($project ?? $this->project)->create());
        $dna = app(CalculateDnaSnapshot::class)->handle($run->id)->snapshot;
        $competency = app(CalculateCompetencyMatrix::class)->handle($dna->id)->snapshot;

        return app(CalculateSkillGapSnapshot::class)->handle($competency->id)->snapshot;
    }

    /** All competencies at or above target: no material gaps. */
    public static function noGaps(stdClass $result): void
    {
        StoredResults::set($result, [
            'files_analyzable' => 20, 'files_parsed' => 20, 'files_parse_error' => 0,
            'functions_total' => 40, 'complexity_total' => 80, 'complexity_over_threshold' => 0, 'types' => 10,
        ], ['structure/nesting-depth' => 0, 'structure/function-length' => 0, 'structure/parameter-count' => 0, 'structure/class-length' => 0]);
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
        return '/api/v1/projects/'.($project ?? $this->project)->id.'/skill-gaps';
    }

    public function test_the_owner_lists_skill_gap_snapshots_newest_first(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');
        $older = $this->analyze(CalculateDnaSnapshotTest::rateable(...));
        Carbon::setTestNow('2026-10-11 10:00:00');
        $newer = $this->analyze(self::noGaps(...));
        Carbon::setTestNow();

        $response = $this->fetch($this->listPath());

        $response->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('data.1.id', $older->id);
        $this->assertSame([
            'id' => $older->id,
            'type' => 'skill_gap_snapshot',
            'project_id' => $this->project->id,
            'competency_snapshot_id' => $older->competency_snapshot_id,
            'dna_snapshot_id' => $older->dna_snapshot_id,
            'analysis_run_id' => $older->analysis_run_id,
            'source_snapshot_id' => $older->source_snapshot_id,
            'status' => 'GAPS_IDENTIFIED',
            'skill_gap_version' => '1.0.0',
            'target_profile' => ['key' => 'ENGINEERING_STANDARD', 'version' => '1.0.0'],
            'competency_version' => '1.0.0',
            'summary' => [
                'competencies' => 4,
                'material_gaps' => 1,
                'statuses' => ['GAP' => 1, 'NO_GAP' => 3, 'INSUFFICIENT_EVIDENCE' => 0, 'UNSUPPORTED' => 0, 'MISSING' => 0, 'NOT_TARGETED' => 0],
                'priorities' => ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 1],
            ],
            'created_at' => '2026-10-10T10:00:00Z',
        ], $response->json('data.1'));
        $this->assertSame('NO_MATERIAL_GAPS', $response->json('data.0.status'));
    }

    public function test_ties_are_ordered_by_id_and_the_list_is_paginated(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');
        $ids = [$this->analyze()->id, $this->analyze()->id, $this->analyze()->id];
        Carbon::setTestNow();
        rsort($ids);

        $this->fetch($this->listPath().'?per_page=2')->assertOk()->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.1.id', $ids[1]);
        $this->fetch($this->listPath().'?per_page=2&page=2')->assertOk()->assertJsonPath('data.0.id', $ids[2]);
        $this->fetch($this->listPath())->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_a_project_without_skill_gaps_has_an_empty_list(): void
    {
        $this->fetch($this->listPath())->assertOk()->assertExactJson([
            'data' => [],
            'meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1],
        ]);
    }

    public function test_the_detail_returns_stored_gaps_targets_and_provenance(): void
    {
        $snapshot = $this->analyze(CalculateDnaSnapshotTest::rateable(...));

        $data = $this->fetch("{$this->listPath()}/{$snapshot->id}")->assertOk()->json('data');

        $this->assertSame([
            'id', 'type', 'project_id', 'competency_snapshot_id', 'dna_snapshot_id', 'analysis_run_id', 'source_snapshot_id', 'status',
            'skill_gap_version', 'specification_fingerprint', 'target_profile', 'thresholds', 'competency_version', 'dna_scoring_version',
            'created_at', 'summary', 'languages', 'competency_snapshot', 'source_snapshot', 'results',
        ], array_keys($data));
        $this->assertSame(SkillGapSpecification::v1_0_0()->fingerprint(), $data['specification_fingerprint']);
        $this->assertSame('ENGINEERING_STANDARD', $data['target_profile']['key']);
        $this->assertStringContainsString('Not a job title or seniority level', (string) $data['target_profile']['description']);
        $this->assertSame([
            'material_gap' => '0.0500',
            'priorities' => [
                ['priority' => 'LOW', 'minimum_gap' => '0.0500'],
                ['priority' => 'MEDIUM', 'minimum_gap' => '0.1500'],
                ['priority' => 'HIGH', 'minimum_gap' => '0.3000'],
            ],
            'high_priority_minimum_evidence_quality' => '0.6000',
        ], $data['thresholds']);
        $this->assertSame($snapshot->competency_snapshot_id, $data['competency_snapshot']['id']);
        $this->assertSame(['javascript', 'php', 'python'], $data['languages']);

        $this->assertSame(['COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE', 'CODE_HYGIENE'], array_column($data['results'], 'competency_key'));
        $this->assertSame([
            'competency_key' => 'CODE_HYGIENE',
            'name' => 'Code hygiene',
            'status' => 'GAP',
            'current_score' => '0.6000',
            'target_score' => '0.9000',
            'raw_gap' => '0.3000',
            'material_gap' => true,
            'priority' => 'HIGH',
            'priority_capped' => false,
            'evidence_quality' => '0.9000',
            'competency_status' => 'ASSESSED',
            'current_level' => 'DEVELOPING',
            'target_rationale' => 'Syntax validity is expected of analyzable code: 0.90 allows about 2.5% of files with syntax errors.',
            'limitations' => [],
            'evidence' => [['source' => 'CODE_HYGIENE.syntax_error_share', 'status' => 'AVAILABLE', 'value' => '0.1000', 'score' => '0.6000']],
        ], $data['results'][3]);
        $this->assertSame(['NO_GAP', '0.7500', '0.7500', '0.0000', false, null], [
            $data['results'][0]['status'], $data['results'][0]['current_score'], $data['results'][0]['target_score'],
            $data['results'][0]['raw_gap'], $data['results'][0]['material_gap'], $data['results'][0]['priority'],
        ]);
    }

    public function test_numbers_keep_their_exact_decimal_representation(): void
    {
        // FUNCTION_DESIGN 0.7857 against 0.75: no gap; COMPLEXITY_MANAGEMENT 1.0; CODE_HYGIENE 1.0.
        // TYPE_STRUCTURE: 1 large type of 10 -> 0.5 against 0.75: raw gap 0.2500.
        $snapshot = $this->analyze(function (stdClass $result): void {
            StoredResults::set($result, [
                'files_analyzable' => 10, 'files_parsed' => 10, 'files_parse_error' => 0,
                'functions_total' => 7, 'complexity_total' => 14, 'complexity_over_threshold' => 0, 'types' => 10,
            ], ['structure/parameter-count' => 1, 'structure/class-length' => 1]);
        });

        $body = (string) $this->fetch("{$this->listPath()}/{$snapshot->id}")->getContent();

        $this->assertStringContainsString('"current_score":"0.7857","target_score":"0.7500","raw_gap":"0.0000","material_gap":false', $body);
        $this->assertStringContainsString('"current_score":"0.5000","target_score":"0.7500","raw_gap":"0.2500","material_gap":true,"priority":"MEDIUM"', $body);
    }

    public function test_insufficient_data_and_no_material_gaps_are_distinct_states(): void
    {
        $insufficient = $this->analyze(function (stdClass $result): void {
            StoredResults::set($result, ['files_analyzable' => 2, 'files_parsed' => 2, 'files_parse_error' => 0, 'functions_total' => 2, 'complexity_total' => 2, 'types' => 0]);
        });
        $none = $this->analyze(self::noGaps(...));

        $data = $this->fetch("{$this->listPath()}/{$insufficient->id}")->json('data');
        $this->assertSame('INSUFFICIENT_DATA', $data['status']);
        foreach ($data['results'] as $result) {
            $this->assertSame(['INSUFFICIENT_EVIDENCE', null, null, null, null], [
                $result['status'], $result['current_score'], $result['raw_gap'], $result['material_gap'], $result['priority'],
            ]);
            $this->assertNotNull($result['target_score']);
        }

        $data = $this->fetch("{$this->listPath()}/{$none->id}")->json('data');
        $this->assertSame('NO_MATERIAL_GAPS', $data['status']);
        $this->assertSame(0, $data['summary']['material_gaps']);
        $this->assertSame(['NO_GAP'], array_values(array_unique(array_column($data['results'], 'status'))));
    }

    public function test_other_users_missing_projects_and_foreign_snapshots_are_all_404(): void
    {
        $snapshot = $this->analyze();
        $stranger = User::factory()->create();
        $otherProject = Project::factory()->for($this->owner)->create();
        $foreign = $this->analyze(null, Project::factory()->for($stranger)->create());

        foreach ([
            [$this->listPath(), $stranger],
            ["{$this->listPath()}/{$snapshot->id}", $stranger],
            ['/api/v1/projects/'.strtolower((string) Str::ulid()).'/skill-gaps', $this->owner],
            ["{$this->listPath()}/".strtolower((string) Str::ulid()), $this->owner],
            ["{$this->listPath($otherProject)}/{$snapshot->id}", $this->owner],
            ["{$this->listPath()}/{$foreign->id}", $this->owner],
            ['/api/v1/projects/'.$foreign->project_id."/skill-gaps/{$foreign->id}", $this->owner],
        ] as [$path, $user]) {
            $this->fetch($path, $user)->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        }
    }

    public function test_guests_are_rejected(): void
    {
        $snapshot = $this->analyze();

        $this->getJson($this->listPath())->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->getJson("{$this->listPath()}/{$snapshot->id}")->assertStatus(401);
    }

    public function test_skill_gaps_are_read_only_and_accept_no_client_values(): void
    {
        $snapshot = $this->analyze(CalculateDnaSnapshotTest::rateable(...));
        $before = DB::table('skill_gap_results')->where('skill_gap_snapshot_id', $snapshot->id)->orderBy('position')->get();
        $payload = ['target' => 0.95, 'target_score' => '0.9500', 'priority' => 'LOW', 'raw_gap' => '0.0000', 'skill_gap_version' => '9.9.9'];

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->asUser($this->owner)->{$method}($this->listPath(), $payload)->assertStatus(405);
            $this->asUser($this->owner)->{$method}("{$this->listPath()}/{$snapshot->id}", $payload)->assertStatus(405);
        }
        // Query parameters are not inputs either.
        $this->fetch("{$this->listPath()}/{$snapshot->id}?target=0.95&target_profile=SENIOR")->assertOk()->assertJsonPath('data.results.3.target_score', '0.9000');
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_contains($route->uri(), 'skill-gap')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri());
            }
        }
        $this->assertEquals($before, DB::table('skill_gap_results')->where('skill_gap_snapshot_id', $snapshot->id)->orderBy('position')->get());
    }

    public function test_responses_expose_no_storage_details_urls_secrets_or_analyzer_payload(): void
    {
        $snapshot = $this->analyze(CalculateDnaSnapshotTest::rateable(...));
        $source = SourceSnapshot::query()->findOrFail($snapshot->source_snapshot_id);

        foreach ([$this->fetch($this->listPath())->getContent(), $this->fetch("{$this->listPath()}/{$snapshot->id}")->getContent()] as $body) {
            foreach ([$source->storage_key, 'storage_key', 'storage_disk', 'X-Amz', 'http://', 'https://', 'analyzer:8000', 'minio',
                'user_id', $this->owner->id, $this->owner->email, 'hmac', 'request_id', 'by_language', '"findings":', '"ir"'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, (string) $body);
            }
            $this->assertDoesNotMatchRegularExpression('/\b(junior|middle|senior|expert)\b/i', (string) $body);
        }
    }

    public function test_queries_do_not_grow_with_the_number_of_snapshots(): void
    {
        $this->analyze();
        $count = function (string $path): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->fetch($path)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $list = $count($this->listPath());
        $latest = $this->analyze();
        $detail = $count("{$this->listPath()}/{$latest->id}");
        for ($i = 0; $i < 3; $i++) {
            $this->analyze();
        }

        $this->assertSame($list, $count($this->listPath()));
        $this->assertSame($detail, $count("{$this->listPath()}/{$latest->id}"));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->fetch($this->listPath())->assertOk();
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        $this->assertStringNotContainsString('skill_gap_results', $sql, 'the list reads no result rows');
        $this->assertStringNotContainsString('competency_snapshots', $sql);
    }
}
