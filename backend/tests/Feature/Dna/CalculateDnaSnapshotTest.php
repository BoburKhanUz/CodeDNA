<?php

declare(strict_types=1);

namespace Tests\Feature\Dna;

use App\Actions\Dna\CalculateDnaSnapshot;
use App\Enums\AnalysisRunStatus;
use App\Enums\Dna\DnaScoringFailure;
use App\Enums\DnaSnapshotStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\AnalysisRun;
use App\Models\DnaSnapshot;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Services\Dna\CodeDnaScoringEngine;
use App\Services\Dna\DnaScoringException;
use App\Services\Dna\ScoringSpecification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use stdClass;
use Tests\Support\StoredResults;
use Tests\TestCase;

/**
 * App\Actions\Dna\CalculateDnaSnapshot against PostgreSQL, from results
 * persisted the way the Phase 10 pipeline stores them.
 */
final class CalculateDnaSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Scoring reads the database only: any HTTP call fails the test.
        Http::preventStrayRequests();
    }

    /** A result large enough for every dimension: 40 functions, 10 types, 10 files. */
    public static function rateable(stdClass $result): void
    {
        StoredResults::set($result, [
            'files_analyzable' => 10, 'files_parsed' => 9, 'files_parse_error' => 1,
            'functions_total' => 40, 'complexity_total' => 160, 'complexity_over_threshold' => 2, 'types' => 10,
        ], ['structure/function-length' => 1, 'parse/syntax-error' => 1]);
    }

    private function calculate(string $runId): DnaSnapshot
    {
        return app(CalculateDnaSnapshot::class)->handle($runId)->snapshot;
    }

    private function assertFailure(DnaScoringFailure $failure, string $runId): void
    {
        try {
            $this->calculate($runId);
            $this->fail("Expected {$failure->value}");
        } catch (DnaScoringException $e) {
            $this->assertSame($failure, $e->failure);
            $this->assertSame($failure->message(), $e->getMessage());
            $this->assertStringNotContainsString($runId, $e->getMessage());
        }
    }

    public function test_a_succeeded_static_analysis_run_gets_a_dna_snapshot(): void
    {
        $run = StoredResults::succeededRun('static_analysis', self::rateable(...));

        $calculated = app(CalculateDnaSnapshot::class)->handle($run->id);
        $dna = DnaSnapshot::query()->findOrFail($calculated->snapshot->id);

        $this->assertTrue($calculated->created);
        $this->assertSame(DnaSnapshotStatus::Ready, $dna->status);
        $this->assertSame('0.8050', $dna->overall_score);
        $this->assertSame('0.9000', $dna->data_quality);
        $this->assertSame('1.0.0', $dna->scoring_version);
        // JSONB does not keep key order; dimensions are keyed by their identifier.
        $this->assertSame(['COMPLEXITY' => '0.8125', 'STRUCTURE' => '0.9000', 'CODE_HYGIENE' => '0.6000'], [
            'COMPLEXITY' => $dna->dimensions['COMPLEXITY']['score'],
            'STRUCTURE' => $dna->dimensions['STRUCTURE']['score'],
            'CODE_HYGIENE' => $dna->dimensions['CODE_HYGIENE']['score'],
        ]);
        $this->assertEqualsCanonicalizing(['COMPLEXITY', 'STRUCTURE', 'CODE_HYGIENE'], array_keys($dna->dimensions));
        // Linkage: run, source snapshot, project and owner all come from the run.
        $this->assertSame($run->id, $dna->analysis_run_id);
        $this->assertSame($run->source_snapshot_id, $dna->source_snapshot_id);
        $this->assertSame($run->project_id, $dna->project_id);
        $this->assertSame($run->project->user_id, $dna->user_id);
        $this->assertTrue($dna->sourceSnapshot->is($run->sourceSnapshot));
        $this->assertTrue($run->dnaSnapshots()->sole()->is($dna));
        // The verified result's hash and versions are carried over unchanged.
        $this->assertSame($run->result_hash, $dna->result_hash);
        $this->assertSame($run->result->result_hash, $dna->result_hash);
        $this->assertSame(['0.2.0', '1.1', '1.0', '1.0'], [$dna->analyzer_version, $dna->ir_version, $dna->metrics_version, $dna->contract_version]);
        $this->assertEquals([
            'analysis_run_id' => $run->id,
            'source_snapshot_id' => $run->source_snapshot_id,
            'result_type' => 'static_analysis',
            'result_hash' => $run->result_hash,
            'metrics_version' => '1.0',
        ], $dna->evidence['source']);
        $this->assertSame(ScoringSpecification::v1_0_0()->fingerprint(), $dna->evidence['specification_fingerprint']);
        $this->assertSame('1.0.0', $dna->evidence['scoring_version']);
        // No interpretation is produced by scoring.
        $this->assertNull($dna->competencies);
        $this->assertNull($dna->strengths);
        $this->assertNull($dna->weaknesses);
        // What is stored is exactly what the engine computes from the stored result.
        $engine = (new CodeDnaScoringEngine)->score($run->result->decoded(), ScoringSpecification::v1_0_0());
        $this->assertEquals($engine->dimensions, $dna->dimensions);
        $this->assertEquals($engine->calculation['aggregation'], $dna->evidence['aggregation']);
    }

    public function test_the_captured_result_is_stored_as_insufficient_data(): void
    {
        $run = StoredResults::succeededRun();

        $dna = $this->calculate($run->id)->refresh();

        $this->assertSame(DnaSnapshotStatus::InsufficientData, $dna->status);
        $this->assertNull($dna->overall_score);
        $this->assertSame('0.3841', $dna->data_quality);
        $this->assertSame('UNAVAILABLE', $dna->dimensions['COMPLEXITY']['status']);
    }

    public function test_only_succeeded_runs_are_scored(): void
    {
        $runs = [
            AnalysisRunStatus::Queued->value => AnalysisRun::factory()->create(['result_type' => 'static_analysis']),
            AnalysisRunStatus::Running->value => AnalysisRun::factory()->running()->create(['result_type' => 'static_analysis']),
            AnalysisRunStatus::Failed->value => AnalysisRun::factory()->failed()->create(['result_type' => 'static_analysis']),
            AnalysisRunStatus::Cancelled->value => AnalysisRun::factory()->cancelled()->create(['result_type' => 'static_analysis']),
        ];
        foreach ($runs as $run) {
            $this->assertFailure(DnaScoringFailure::RunNotSucceeded, $run->id);
        }
        $this->assertFailure(DnaScoringFailure::RunNotFound, strtolower((string) Str::ulid()));

        $this->assertSame(0, DnaSnapshot::query()->count());
    }

    public function test_only_a_verified_static_analysis_result_of_a_supported_metrics_version_is_scored(): void
    {
        // SUCCEEDED before Phase 10 (no stored result).
        $this->assertFailure(DnaScoringFailure::ResultMissing, AnalysisRun::factory()->succeeded()->create()->id);
        // Foundation results have no metrics.
        $this->assertFailure(DnaScoringFailure::ResultTypeNotScoreable, StoredResults::succeededRun('foundation')->id);
        // A metrics version 1.0.0 does not know.
        $future = StoredResults::succeededRun('static_analysis', function (stdClass $result): void {
            $result->metrics->version = '2.0';
            $result->versions->metrics = '2.0';
        });
        $this->assertFailure(DnaScoringFailure::MetricsVersionUnsupported, $future->id);
        // Inconsistent counts.
        $inconsistent = StoredResults::succeededRun('static_analysis', function (stdClass $result): void {
            self::rateable($result);
            $result->findings->by_rule->{'structure/function-length'} = 41;
        });
        $this->assertFailure(DnaScoringFailure::ResultInvalid, $inconsistent->id);

        $this->assertSame(0, DnaSnapshot::query()->count());
    }

    public function test_a_stored_result_that_no_longer_matches_its_hash_is_not_scored(): void
    {
        $edited = StoredResults::succeededRun('static_analysis', self::rateable(...));
        $result = $edited->result->decoded();
        $result->metrics->overall->complexity_total = 40;
        DB::table('analysis_results')->where('analysis_run_id', $edited->id)->update(['result' => json_encode($result)]);
        $this->assertFailure(DnaScoringFailure::ResultIntegrityFailed, $edited->id);

        $foreign = StoredResults::succeededRun('static_analysis', self::rateable(...));
        DB::table('analysis_runs')->where('id', $foreign->id)->update(['result_hash' => str_repeat('a', 64)]);
        DB::table('analysis_results')->where('analysis_run_id', $foreign->id)->update(['result_hash' => str_repeat('a', 64)]);
        $this->assertFailure(DnaScoringFailure::ResultIntegrityFailed, $foreign->id);

        $this->assertSame(0, DnaSnapshot::query()->count());
    }

    public function test_scoring_is_idempotent(): void
    {
        $run = StoredResults::succeededRun('static_analysis', self::rateable(...));

        $first = app(CalculateDnaSnapshot::class)->handle($run->id);
        $second = app(CalculateDnaSnapshot::class)->handle($run->id);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertTrue($first->snapshot->is($second->snapshot));
        $this->assertSame(1, DnaSnapshot::query()->count());

        // An existing snapshot is returned as is: the result is not read or scored again.
        DB::table('analysis_results')->where('analysis_run_id', $run->id)->update(['result' => '{}']);
        $third = app(CalculateDnaSnapshot::class)->handle($run->id);
        $this->assertFalse($third->created);
        $this->assertTrue($first->snapshot->is($third->snapshot));
    }

    public function test_each_scoring_version_has_its_own_immutable_snapshot(): void
    {
        $run = StoredResults::succeededRun('static_analysis', self::rateable(...));
        $older = DnaSnapshot::factory()->create(['analysis_run_id' => $run->id, 'scoring_version' => '0.9.0']);

        $current = $this->calculate($run->id);

        $this->assertSame(['0.9.0', '1.0.0'], $run->dnaSnapshots()->orderBy('scoring_version')->pluck('scoring_version')->all());
        $this->assertSame('0.5000', $older->refresh()->overall_score, 'other versions are untouched');
        $this->expectException(UniqueConstraintViolationException::class);
        DnaSnapshot::factory()->create(['analysis_run_id' => $run->id, 'scoring_version' => $current->scoring_version]);
    }

    public function test_dna_snapshots_cannot_be_changed_or_deleted(): void
    {
        $dna = $this->calculate(StoredResults::succeededRun('static_analysis', self::rateable(...))->id);

        foreach ([
            fn () => $dna->forceFill(['overall_score' => '1.0000'])->save(),
            fn () => $dna->forceFill(['dimensions' => []])->save(),
            fn () => $dna->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('DNA snapshots must not change.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('0.8050', DnaSnapshot::query()->findOrFail($dna->id)->overall_score);
    }

    public function test_ownership_is_taken_from_the_run_only(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceRun = StoredResults::succeededRun('static_analysis', self::rateable(...), SourceSnapshot::factory()->for(Project::factory()->for($alice))->create());
        $bobRun = StoredResults::succeededRun('static_analysis', self::rateable(...), SourceSnapshot::factory()->for(Project::factory()->for($bob))->create());

        $aliceDna = $this->calculate($aliceRun->id);
        $bobDna = $this->calculate($bobRun->id);

        $this->assertSame([$alice->id, $aliceRun->project_id], [$aliceDna->user_id, $aliceDna->project_id]);
        $this->assertSame([$bob->id, $bobRun->project_id], [$bobDna->user_id, $bobDna->project_id]);
        $this->assertSame(1, DnaSnapshot::query()->where('user_id', $alice->id)->count());
        $this->assertSame(1, DnaSnapshot::query()->where('user_id', $bob->id)->count());
    }

    public function test_no_http_endpoint_exposes_scoring(): void
    {
        // DNA snapshots are only read over HTTP (Phase 12); nothing can trigger scoring.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/scor/i', $route->uri(), 'no scoring endpoint');
            if (preg_match('/dna/i', $route->uri()) === 1) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri());
            }
        }
    }

    public function test_scoring_code_has_no_clock_randomness_network_or_ai(): void
    {
        $files = [
            ...glob(app_path('Services/Dna/*.php')) ?: [],
            ...glob(app_path('Services/Dna/Specification/*.php')) ?: [],
            ...glob(app_path('Actions/Dna/*.php')) ?: [],
        ];
        $this->assertNotEmpty($files);
        $forbidden = '/\b(Http|Guzzle|curl_\w+|file_get_contents|fopen|Carbon|now|time|microtime|date|hrtime|rand|mt_rand|random_int|uniqid|Str::|shuffle|array_rand|OpenAI|Anthropic|Claude|LLM|float|round|floor|ceil)\s*[(:]/i';
        foreach ($files as $file) {
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression($forbidden, (string) $code, basename($file));
        }
    }
}
