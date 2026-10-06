<?php

declare(strict_types=1);

namespace Tests\Unit\SkillGap;

use App\Enums\SkillGap\SkillGapFailure;
use App\Enums\SkillGap\SkillGapSnapshotStatus;
use App\Services\Competency\CompetencyEngine;
use App\Services\Competency\CompetencySpecification;
use App\Services\Dna\CodeDnaScoringEngine;
use App\Services\Dna\ScoringSpecification;
use App\Services\SkillGap\SkillGapAnalysis;
use App\Services\SkillGap\SkillGapEngine;
use App\Services\SkillGap\SkillGapException;
use App\Services\SkillGap\SkillGapSpecification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeAnalyzer;
use Tests\Support\StoredResults;

/**
 * SkillGapEngine 1.0.0 on competency matrices produced by the real DNA and
 * competency engines, and on hand-built matrices where exact scores matter.
 */
final class SkillGapEngineTest extends TestCase
{
    private const RATEABLE = [
        'files_analyzable' => 10, 'files_parsed' => 9, 'files_parse_error' => 1,
        'functions_total' => 40, 'complexity_total' => 160, 'complexity_over_threshold' => 2, 'types' => 10,
    ];

    private const FINDINGS = [
        'structure/nesting-depth' => 0, 'structure/function-length' => 1,
        'structure/parameter-count' => 0, 'structure/class-length' => 0,
    ];

    /**
     * The competency matrix the engines produce for the captured result with these counts.
     *
     * @param  array<string, int>|null  $overall  null: the captured result unchanged
     * @param  array<string, int>  $byRule
     * @param  list<string>  $languages
     * @return list<array<string, mixed>>
     */
    private function matrix(?array $overall = [], array $byRule = [], array $languages = []): array
    {
        $result = FakeAnalyzer::fixture('static_analysis');
        if ($overall !== null) {
            StoredResults::set($result, $overall + self::RATEABLE, $byRule + self::FINDINGS);
        }
        $dna = (new CodeDnaScoringEngine)->score($result, ScoringSpecification::v1_0_0());

        return (new CompetencyEngine)->assess($dna->dimensions, $dna->calculation, '1.0.0', $languages, CompetencySpecification::v1_0_0())->competencies;
    }

    /**
     * A hand-built matrix: every competency ASSESSED with the given scores.
     *
     * @param  array<string, array<string, mixed>|null>  $overrides  key => fields (null removes it)
     * @return list<array<string, mixed>>
     */
    private function handBuilt(array $overrides = []): array
    {
        $matrix = [];
        foreach ($this->matrix() as $competency) {
            $key = $competency['key'];
            if (array_key_exists($key, $overrides) && $overrides[$key] === null) {
                continue;
            }
            $matrix[] = ($overrides[$key] ?? []) + $competency;
        }

        return $matrix;
    }

    /**
     * @param  list<array<string, mixed>>  $matrix
     */
    private function analyze(array $matrix): SkillGapAnalysis
    {
        return (new SkillGapEngine)->analyze($matrix, SkillGapSpecification::v1_0_0());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function byKey(SkillGapAnalysis $analysis): array
    {
        return array_column($analysis->results, null, 'competency_key');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<mixed>
     */
    private function gap(array $result): array
    {
        return [$result['status'], $result['current_score'], $result['target_score'], $result['raw_gap'], $result['material_gap'], $result['priority']];
    }

    public function test_the_same_matrix_always_gives_the_same_gaps(): void
    {
        $matrix = $this->matrix();
        $first = $this->analyze($matrix);

        for ($i = 0; $i < 5; $i++) {
            $this->assertEquals($first, $this->analyze($matrix));
        }
        $this->assertSame(json_encode($first), json_encode($this->analyze(array_reverse($matrix))), 'input order does not matter');
    }

    public function test_a_rateable_project_against_the_engineering_standard(): void
    {
        $analysis = $this->analyze($this->matrix());
        $gaps = $this->byKey($analysis);

        $this->assertSame(['COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE', 'CODE_HYGIENE'], array_column($analysis->results, 'competency_key'));
        $this->assertSame(['NO_GAP', '0.7500', '0.7500', '0.0000', false, null], $this->gap($gaps['COMPLEXITY_MANAGEMENT']), 'exactly at target');
        $this->assertSame(['NO_GAP', '0.9000', '0.7500', '0.0000', false, null], $this->gap($gaps['FUNCTION_DESIGN']), 'above target: never negative');
        $this->assertSame(['NO_GAP', '1.0000', '0.7500', '0.0000', false, null], $this->gap($gaps['TYPE_STRUCTURE']));
        $this->assertSame(['GAP', '0.6000', '0.9000', '0.3000', true, 'HIGH'], $this->gap($gaps['CODE_HYGIENE']));
        $this->assertFalse($gaps['CODE_HYGIENE']['priority_capped']);
        $this->assertSame(['0.9000', 'ASSESSED', 'DEVELOPING'], [$gaps['CODE_HYGIENE']['evidence_quality'], $gaps['CODE_HYGIENE']['competency_status'], $gaps['CODE_HYGIENE']['current_level']]);
        $this->assertSame(SkillGapSnapshotStatus::GapsIdentified, $analysis->status);
        $this->assertSame([
            'competencies' => 4,
            'material_gaps' => 1,
            'statuses' => ['GAP' => 1, 'NO_GAP' => 3, 'INSUFFICIENT_EVIDENCE' => 0, 'UNSUPPORTED' => 0, 'MISSING' => 0, 'NOT_TARGETED' => 0],
            'priorities' => ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 1],
        ], $analysis->summary, 'counts only: there is no aggregate gap');
    }

    /**
     * Target 0.75 (COMPLEXITY_MANAGEMENT), material threshold 0.05.
     *
     * @return iterable<string, array{string, string, string, bool, string|null}>
     */
    public static function materiality(): iterable
    {
        yield 'above target' => ['0.8000', 'NO_GAP', '0.0000', false, null];
        yield 'exactly at target' => ['0.7500', 'NO_GAP', '0.0000', false, null];
        yield 'raw gap just below the threshold' => ['0.7001', 'NO_GAP', '0.0499', false, null];
        yield 'raw gap exactly at the threshold' => ['0.7000', 'GAP', '0.0500', true, 'LOW'];
        yield 'raw gap just above the threshold' => ['0.6999', 'GAP', '0.0501', true, 'LOW'];
        yield 'just below medium' => ['0.6001', 'GAP', '0.1499', true, 'LOW'];
        yield 'exactly medium' => ['0.6000', 'GAP', '0.1500', true, 'MEDIUM'];
        yield 'just below high' => ['0.4501', 'GAP', '0.2999', true, 'MEDIUM'];
        yield 'exactly high' => ['0.4500', 'GAP', '0.3000', true, 'HIGH'];
        yield 'measured zero' => ['0.0000', 'GAP', '0.7500', true, 'HIGH'];
    }

    #[DataProvider('materiality')]
    public function test_gap_materiality_and_priority_boundaries(string $current, string $status, string $rawGap, bool $material, ?string $priority): void
    {
        $result = $this->byKey($this->analyze($this->handBuilt(['COMPLEXITY_MANAGEMENT' => ['score' => $current, 'evidence_quality' => '0.9000']])))['COMPLEXITY_MANAGEMENT'];

        $this->assertSame([$status, $current, '0.7500', $rawGap, $material, $priority], $this->gap($result));
    }

    public function test_a_large_gap_with_low_evidence_quality_is_not_promoted_to_high(): void
    {
        $atBound = $this->byKey($this->analyze($this->handBuilt(['CODE_HYGIENE' => ['score' => '0.2000', 'evidence_quality' => '0.6000']])))['CODE_HYGIENE'];
        $below = $this->byKey($this->analyze($this->handBuilt(['CODE_HYGIENE' => ['score' => '0.2000', 'evidence_quality' => '0.5999']])))['CODE_HYGIENE'];

        $this->assertSame(['HIGH', false], [$atBound['priority'], $atBound['priority_capped']]);
        $this->assertSame(['MEDIUM', true, '0.7000', '0.5999'], [$below['priority'], $below['priority_capped'], $below['raw_gap'], $below['evidence_quality']]);
    }

    public function test_the_captured_result_has_gaps_only_where_evidence_exists(): void
    {
        // 3 functions, 1 type, 3 files of which 1 has a syntax error.
        $analysis = $this->analyze($this->matrix(null));
        $gaps = $this->byKey($analysis);

        foreach (['COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE'] as $key) {
            $this->assertSame(['INSUFFICIENT_EVIDENCE', null, '0.7500', null, null, null], $this->gap($gaps[$key]), $key);
        }
        // A measured 0 against 0.90: a full 0.90 gap, capped at MEDIUM because evidence quality is 0.5984.
        $this->assertSame(['GAP', '0.0000', '0.9000', '0.9000', true, 'MEDIUM'], $this->gap($gaps['CODE_HYGIENE']));
        $this->assertTrue($gaps['CODE_HYGIENE']['priority_capped']);
        $this->assertSame('0.5984', $gaps['CODE_HYGIENE']['evidence_quality']);
        $this->assertSame('0.3484', $gaps['TYPE_STRUCTURE']['evidence_quality'], 'preserved for unmeasured competencies');
    }

    public function test_unavailable_competencies_have_no_gap_and_never_target_minus_zero(): void
    {
        $analysis = $this->analyze($this->handBuilt([
            'COMPLEXITY_MANAGEMENT' => ['status' => 'UNSUPPORTED', 'score' => null, 'level' => null],
            'FUNCTION_DESIGN' => ['status' => 'MISSING', 'score' => null, 'level' => null],
            'TYPE_STRUCTURE' => ['status' => 'INSUFFICIENT_EVIDENCE', 'score' => null, 'level' => null],
            'CODE_HYGIENE' => null,
        ]));
        $gaps = $this->byKey($analysis);

        $this->assertSame(['UNSUPPORTED', null, '0.7500', null, null, null], $this->gap($gaps['COMPLEXITY_MANAGEMENT']));
        $this->assertSame(['MISSING', null, '0.7500', null, null, null], $this->gap($gaps['FUNCTION_DESIGN']));
        $this->assertSame(['INSUFFICIENT_EVIDENCE', null, '0.7500', null, null, null], $this->gap($gaps['TYPE_STRUCTURE']));
        // Absent from the competency snapshot altogether.
        $this->assertSame(['MISSING', null, '0.9000', null, null, null], $this->gap($gaps['CODE_HYGIENE']));
        $this->assertSame([null, null], [$gaps['CODE_HYGIENE']['competency_status'], $gaps['CODE_HYGIENE']['evidence_quality']]);
        $this->assertSame(SkillGapSnapshotStatus::InsufficientData, $analysis->status);
        $this->assertSame(0, $analysis->summary['material_gaps']);
    }

    public function test_no_material_gaps_is_distinct_from_no_data(): void
    {
        $none = $this->analyze($this->handBuilt([
            'CODE_HYGIENE' => ['score' => '0.8600', 'level' => 'STRONG'],
            'TYPE_STRUCTURE' => ['status' => 'INSUFFICIENT_EVIDENCE', 'score' => null, 'level' => null],
        ]));

        $this->assertSame(SkillGapSnapshotStatus::NoMaterialGaps, $none->status);
        $this->assertSame('0.0400', $this->byKey($none)['CODE_HYGIENE']['raw_gap'], 'the raw gap is kept even when immaterial');
        $this->assertSame(['GAP' => 0, 'NO_GAP' => 3, 'INSUFFICIENT_EVIDENCE' => 1, 'UNSUPPORTED' => 0, 'MISSING' => 0, 'NOT_TARGETED' => 0], $none->summary['statuses']);
    }

    public function test_untargeted_competencies_get_no_target(): void
    {
        $matrix = $this->handBuilt();
        $matrix[] = ['key' => 'A_FUTURE_COMPETENCY', 'name' => 'Future', 'status' => 'ASSESSED', 'score' => '0.1000', 'level' => 'NOT_ESTABLISHED',
            'evidence_quality' => '0.9000', 'limitations' => [], 'evidence' => []];
        $analysis = $this->analyze($matrix);
        $results = $analysis->results;
        $untargeted = end($results);

        $this->assertSame('A_FUTURE_COMPETENCY', $untargeted['competency_key'], 'after the targeted competencies');
        $this->assertSame(['NOT_TARGETED', '0.1000', null, null, null, null], $this->gap($untargeted));
        $this->assertSame(1, $analysis->summary['statuses']['NOT_TARGETED']);
    }

    public function test_untargeted_competencies_are_not_targeted_whatever_their_status_and_sorted_by_key(): void
    {
        $matrix = $this->handBuilt();
        $matrix[] = ['key' => 'Z_UNTARGETED', 'name' => 'Z', 'status' => 'INSUFFICIENT_EVIDENCE', 'score' => null, 'level' => null,
            'evidence_quality' => '0.3000', 'limitations' => [], 'evidence' => []];
        $matrix[] = ['key' => 'M_UNTARGETED', 'name' => 'M', 'status' => 'ASSESSED', 'score' => '0.5000', 'level' => 'DEVELOPING',
            'evidence_quality' => '0.9000', 'limitations' => [], 'evidence' => []];
        $results = $this->analyze($matrix)->results;

        $this->assertSame(['M_UNTARGETED', 'Z_UNTARGETED'], array_column(array_slice($results, 4), 'competency_key'));
        $this->assertSame(['NOT_TARGETED', null, null, null, null, null], $this->gap($results[5]));
        $this->assertSame('INSUFFICIENT_EVIDENCE', $results[5]['competency_status']);
    }

    public function test_project_size_does_not_change_gaps(): void
    {
        $small = $this->analyze($this->matrix());
        $large = $this->analyze($this->matrix(
            ['files_analyzable' => 100, 'files_parsed' => 90, 'files_parse_error' => 10, 'functions_total' => 400, 'complexity_total' => 1600, 'complexity_over_threshold' => 20, 'types' => 100],
            ['structure/function-length' => 10],
        ));

        foreach ($this->byKey($small) as $key => $result) {
            $this->assertSame($this->gap($result), $this->gap($this->byKey($large)[$key]), $key);
        }
        $this->assertNotSame($this->byKey($small)['CODE_HYGIENE']['evidence_quality'], $this->byKey($large)['CODE_HYGIENE']['evidence_quality']);
    }

    public function test_language_limitations_and_evidence_are_carried_over(): void
    {
        $gaps = $this->byKey($this->analyze($this->matrix([], [], ['php', 'c'])));

        $this->assertSame([['language' => 'c', 'note' => 'Parsed without a preprocessor: decisions hidden in macros are not counted and #if branches are all parsed.']], $gaps['CODE_HYGIENE']['limitations']);
        $this->assertSame([], $gaps['TYPE_STRUCTURE']['limitations']);
        $this->assertSame([
            ['source' => 'COMPLEXITY.mean_cyclomatic_complexity', 'status' => 'AVAILABLE', 'value' => '4.0000', 'score' => '0.7500'],
            ['source' => 'COMPLEXITY.complex_function_share', 'status' => 'AVAILABLE', 'value' => '0.0500', 'score' => '0.7500'],
        ], $gaps['COMPLEXITY_MANAGEMENT']['evidence']);
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>|null>}>
     */
    public static function invalidMatrices(): iterable
    {
        yield 'unknown status' => [['CODE_HYGIENE' => ['status' => 'GOOD']]];
        yield 'score above 1' => [['CODE_HYGIENE' => ['score' => '1.0001']]];
        yield 'float score' => [['CODE_HYGIENE' => ['score' => 0.6]]];
        yield 'assessed without score' => [['CODE_HYGIENE' => ['score' => null]]];
        yield 'unknown level' => [['CODE_HYGIENE' => ['level' => 'SENIOR']]];
        yield 'no evidence quality' => [['CODE_HYGIENE' => ['evidence_quality' => null]]];
        yield 'evidence not a list' => [['CODE_HYGIENE' => ['evidence' => 'x']]];
        yield 'limitation without note' => [['CODE_HYGIENE' => ['limitations' => [['language' => 'c']]]]];
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $overrides
     */
    #[DataProvider('invalidMatrices')]
    public function test_malformed_matrices_are_rejected(array $overrides): void
    {
        try {
            $this->analyze($this->handBuilt($overrides));
            $this->fail('accepted a malformed matrix');
        } catch (SkillGapException $e) {
            $this->assertSame(SkillGapFailure::CompetencySnapshotInvalid, $e->failure);
        }
    }

    public function test_duplicate_competencies_are_rejected(): void
    {
        $matrix = $this->handBuilt();
        $matrix[] = $matrix[0];

        $this->expectException(SkillGapException::class);
        $this->analyze($matrix);
    }
}
