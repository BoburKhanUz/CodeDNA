<?php

declare(strict_types=1);

namespace Tests\Unit\Competency;

use App\Enums\Competency\CompetencyFailure;
use App\Enums\Competency\CompetencySnapshotStatus;
use App\Services\Competency\CompetencyDefinition;
use App\Services\Competency\CompetencyEngine;
use App\Services\Competency\CompetencyException;
use App\Services\Competency\CompetencyResult;
use App\Services\Competency\CompetencySpecification;
use App\Services\Competency\EvidenceRule;
use App\Services\Dna\CodeDnaScoringEngine;
use App\Services\Dna\ScoringSpecification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeAnalyzer;
use Tests\Support\StoredResults;

/**
 * CompetencyEngine 1.0.0 on DNA produced by the real scoring engine 1.0.0
 * from the captured analyzer response, and on hand-built DNA where exact
 * component scores are needed.
 */
final class CompetencyEngineTest extends TestCase
{
    /** 40 functions, 10 types, 10 files (9 parsed, 1 with syntax errors). */
    private const RATEABLE = [
        'files_analyzable' => 10, 'files_parsed' => 9, 'files_parse_error' => 1,
        'functions_total' => 40, 'complexity_total' => 160, 'complexity_over_threshold' => 2, 'types' => 10,
    ];

    private const FINDINGS = [
        'structure/nesting-depth' => 0, 'structure/function-length' => 1,
        'structure/parameter-count' => 0, 'structure/class-length' => 0,
    ];

    /**
     * DNA dimensions and evidence as the scoring engine stores them.
     *
     * @param  array<string, int|null>  $overall
     * @param  array<string, int>  $byRule
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function dna(array $overall = [], array $byRule = []): array
    {
        $result = FakeAnalyzer::fixture('static_analysis');
        StoredResults::set($result, $overall + self::RATEABLE, $byRule + self::FINDINGS);
        $score = (new CodeDnaScoringEngine)->score($result, ScoringSpecification::v1_0_0());

        return [$score->dimensions, ['specification_fingerprint' => 'x', ...$score->calculation]];
    }

    /**
     * @param  array<string, mixed>  $dimensions
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $languages
     */
    private function assess(array $dimensions, array $evidence, array $languages = [], string $scoring = '1.0.0'): CompetencyResult
    {
        return (new CompetencyEngine)->assess($dimensions, $evidence, $scoring, $languages, CompetencySpecification::v1_0_0());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function byKey(CompetencyResult $result): array
    {
        return array_column($result->competencies, null, 'key');
    }

    /**
     * Hand-built DNA: every component AVAILABLE with the given score unless overridden.
     *
     * @param  array<string, array<string, mixed>|null>  $components  "DIMENSION.component" => fields (null removes it)
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function handBuilt(array $components = []): array
    {
        [$dimensions, $evidence] = $this->dna();
        foreach ($components as $source => $fields) {
            [$dimension, $component] = explode('.', $source);
            if ($fields === null) {
                unset($dimensions[$dimension]['components'][$component]);

                continue;
            }
            $dimensions[$dimension]['components'][$component] = $fields + $dimensions[$dimension]['components'][$component];
        }

        return [$dimensions, $evidence];
    }

    public function test_the_same_dna_always_gives_the_same_matrix(): void
    {
        [$dimensions, $evidence] = $this->dna();
        $first = $this->assess($dimensions, $evidence, ['python', 'php']);

        for ($i = 0; $i < 5; $i++) {
            $this->assertEquals($first, $this->assess($dimensions, $evidence, ['python', 'php']));
        }
        // Input order does not matter.
        $reversedDimensions = array_reverse($dimensions, true);
        foreach ($reversedDimensions as &$dimension) {
            $dimension['components'] = array_reverse($dimension['components'], true);
        }
        unset($dimension);
        $reordered = $this->assess($reversedDimensions, array_reverse($evidence, true), ['php', 'python']);
        $this->assertSame(json_encode($first), json_encode($reordered));
    }

    public function test_a_rateable_project_is_assessed_from_component_evidence(): void
    {
        [$dimensions, $evidence] = $this->dna();
        $result = $this->assess($dimensions, $evidence);
        $matrix = $this->byKey($result);

        $this->assertSame(CompetencySnapshotStatus::Assessed, $result->status);
        $this->assertSame(['COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE', 'CODE_HYGIENE'], array_column($result->competencies, 'key'));
        // 0.75 × 0.6 + 0.75 × 0.4
        $this->assertSame(['ASSESSED', '0.7500', 'ESTABLISHED'], [$matrix['COMPLEXITY_MANAGEMENT']['status'], $matrix['COMPLEXITY_MANAGEMENT']['score'], $matrix['COMPLEXITY_MANAGEMENT']['level']]);
        // 0.75 × 0.4 + 1 × 0.3 + 1 × 0.3
        $this->assertSame(['0.9000', 'STRONG'], [$matrix['FUNCTION_DESIGN']['score'], $matrix['FUNCTION_DESIGN']['level']]);
        $this->assertSame(['1.0000', 'STRONG'], [$matrix['TYPE_STRUCTURE']['score'], $matrix['TYPE_STRUCTURE']['level']]);
        $this->assertSame(['0.6000', 'DEVELOPING'], [$matrix['CODE_HYGIENE']['score'], $matrix['CODE_HYGIENE']['level']]);
        // 0.5 × 0.9 (parse coverage) + 0.25 × 0.8 (40/50 functions) + 0.25 × 1 (all evidence available)
        foreach ($matrix as $competency) {
            $this->assertSame('0.9000', $competency['evidence_quality']);
        }
        $this->assertSame(['parse_coverage' => '0.9000', 'evidence_volume' => '0.8000', 'evidence_availability' => '1.0000'], $matrix['CODE_HYGIENE']['evidence_quality_terms']);
        $this->assertSame([
            'competencies' => 4,
            'statuses' => ['ASSESSED' => 4, 'INSUFFICIENT_EVIDENCE' => 0, 'UNSUPPORTED' => 0, 'MISSING' => 0],
            'levels' => ['NOT_ESTABLISHED' => 0, 'DEVELOPING' => 1, 'ESTABLISHED' => 1, 'STRONG' => 2],
        ], $result->summary);
    }

    public function test_evidence_keeps_its_provenance(): void
    {
        [$dimensions, $evidence] = $this->dna();
        $evidenceList = $this->byKey($this->assess($dimensions, $evidence))['FUNCTION_DESIGN']['evidence'];

        $this->assertSame(['STRUCTURE.long_function_share', 'STRUCTURE.long_parameter_list_share', 'COMPLEXITY.deep_nesting_share'], array_column($evidenceList, 'source'));
        $this->assertSame([
            'source' => 'STRUCTURE.long_function_share',
            'dimension' => 'STRUCTURE',
            'component' => 'long_function_share',
            'weight' => '0.4000',
            'required' => true,
            'status' => 'AVAILABLE',
            'value' => '0.0250',
            'score' => '0.7500',
            'best' => '0.0000',
            'worst' => '0.1000',
            'minimum_denominator' => 5,
            'numerator' => ['findings.by_rule.structure/function-length' => 1],
            'denominator' => ['metrics.overall.functions_total' => 40],
        ], $evidenceList[0]);
        // Passed through from the DNA snapshot unchanged.
        $this->assertSame($dimensions['COMPLEXITY']['components']['deep_nesting_share']['score'], $evidenceList[2]['score']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function levelScores(): iterable
    {
        yield 'zero' => ['0.0000', 'NOT_ESTABLISHED'];
        yield 'just below developing' => ['0.3999', 'NOT_ESTABLISHED'];
        yield 'exactly developing' => ['0.4000', 'DEVELOPING'];
        yield 'just above developing' => ['0.4001', 'DEVELOPING'];
        yield 'just below established' => ['0.6499', 'DEVELOPING'];
        yield 'exactly established' => ['0.6500', 'ESTABLISHED'];
        yield 'just below strong' => ['0.8499', 'ESTABLISHED'];
        yield 'exactly strong' => ['0.8500', 'STRONG'];
        yield 'one' => ['1.0000', 'STRONG'];
    }

    #[DataProvider('levelScores')]
    public function test_levels_at_their_boundaries(string $score, string $level): void
    {
        [$dimensions, $evidence] = $this->handBuilt(['CODE_HYGIENE.syntax_error_share' => ['score' => $score]]);
        $hygiene = $this->byKey($this->assess($dimensions, $evidence))['CODE_HYGIENE'];

        $this->assertSame([$score, $level], [$hygiene['score'], $hygiene['level']]);
    }

    public function test_scores_are_weighted_means_rounded_half_up(): void
    {
        [$dimensions, $evidence] = $this->handBuilt([
            'COMPLEXITY.mean_cyclomatic_complexity' => ['score' => '0.3333'],
            'COMPLEXITY.complex_function_share' => ['score' => '0.6667'],
        ]);

        // (0.3333 × 0.6 + 0.6667 × 0.4) = 0.46666 -> 0.4667
        $this->assertSame('0.4667', $this->byKey($this->assess($dimensions, $evidence))['COMPLEXITY_MANAGEMENT']['score']);

        [$dimensions, $evidence] = $this->handBuilt([
            'COMPLEXITY.mean_cyclomatic_complexity' => ['score' => '0.7000'],
            'COMPLEXITY.complex_function_share' => ['score' => '0.5000'],
        ]);
        $complexity = $this->byKey($this->assess($dimensions, $evidence))['COMPLEXITY_MANAGEMENT'];
        $this->assertSame(['0.6200', 'DEVELOPING'], [$complexity['score'], $complexity['level']]);
    }

    public function test_a_measured_zero_is_evidence_and_scores_zero(): void
    {
        [$dimensions, $evidence] = $this->handBuilt(['CODE_HYGIENE.syntax_error_share' => ['score' => '0.0000', 'value' => '0.5000']]);
        $hygiene = $this->byKey($this->assess($dimensions, $evidence))['CODE_HYGIENE'];

        $this->assertSame(['ASSESSED', '0.0000', 'NOT_ESTABLISHED'], [$hygiene['status'], $hygiene['score'], $hygiene['level']]);
    }

    public function test_too_little_code_is_insufficient_evidence_not_a_low_level(): void
    {
        // The captured analyzer response: 3 functions, 1 type, 3 files (1 with a syntax error).
        $score = (new CodeDnaScoringEngine)->score(FakeAnalyzer::fixture('static_analysis'), ScoringSpecification::v1_0_0());
        $result = $this->assess($score->dimensions, $score->calculation);
        $matrix = $this->byKey($result);

        foreach (['COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE'] as $key) {
            $this->assertSame(['INSUFFICIENT_EVIDENCE', null, null], [$matrix[$key]['status'], $matrix[$key]['score'], $matrix[$key]['level']], $key);
        }
        $this->assertSame(['ASSESSED', '0.0000', 'NOT_ESTABLISHED'], [$matrix['CODE_HYGIENE']['status'], $matrix['CODE_HYGIENE']['score'], $matrix['CODE_HYGIENE']['level']]);
        $this->assertSame(CompetencySnapshotStatus::Assessed, $result->status);
        // 0.5 × 0.6667 + 0.25 × 0.06 + 0.25 × 1 = 0.59835 -> 0.5984
        $this->assertSame('0.5984', $matrix['CODE_HYGIENE']['evidence_quality']);
        // 0.5 × 0.6667 + 0.25 × 0.06 + 0.25 × 0 = 0.34835 -> 0.3484
        $this->assertSame('0.3484', $matrix['TYPE_STRUCTURE']['evidence_quality']);
        $this->assertSame(['ASSESSED' => 1, 'INSUFFICIENT_EVIDENCE' => 3, 'UNSUPPORTED' => 0, 'MISSING' => 0], $result->summary['statuses']);
    }

    public function test_nothing_assessable_is_insufficient_data(): void
    {
        [$dimensions, $evidence] = $this->dna(['files_analyzable' => 2, 'files_parsed' => 2, 'files_parse_error' => 0, 'functions_total' => 2, 'complexity_total' => 2, 'types' => 0]);
        $result = $this->assess($dimensions, $evidence);

        $this->assertSame(CompetencySnapshotStatus::InsufficientData, $result->status);
        foreach ($result->competencies as $competency) {
            $this->assertSame(['INSUFFICIENT_EVIDENCE', null, null], [$competency['status'], $competency['score'], $competency['level']]);
        }
    }

    public function test_unsupported_and_missing_evidence_are_not_assessed_and_never_zero(): void
    {
        [$dimensions, $evidence] = $this->handBuilt([
            'STRUCTURE.large_type_share' => ['status' => 'UNSUPPORTED', 'score' => null, 'value' => null],
            'CODE_HYGIENE.syntax_error_share' => null,
        ]);
        $matrix = $this->byKey($this->assess($dimensions, $evidence));

        $this->assertSame(['UNSUPPORTED', null, null], [$matrix['TYPE_STRUCTURE']['status'], $matrix['TYPE_STRUCTURE']['score'], $matrix['TYPE_STRUCTURE']['level']]);
        $this->assertSame(['MISSING', null, null], [$matrix['CODE_HYGIENE']['status'], $matrix['CODE_HYGIENE']['score'], $matrix['CODE_HYGIENE']['level']]);
        $this->assertSame('MISSING', $matrix['CODE_HYGIENE']['evidence'][0]['status']);
        $this->assertSame([], $matrix['CODE_HYGIENE']['evidence'][0]['numerator']);
        // Unaffected competencies stay assessed: one missing metric does not lower others.
        $this->assertSame('ASSESSED', $matrix['FUNCTION_DESIGN']['status']);

        // A whole missing dimension.
        [$dimensions, $evidence] = $this->dna();
        unset($dimensions['COMPLEXITY']);
        $matrix = $this->byKey($this->assess($dimensions, $evidence));
        $this->assertSame('MISSING', $matrix['COMPLEXITY_MANAGEMENT']['status']);
        $this->assertSame('MISSING', $matrix['FUNCTION_DESIGN']['status'], 'deep_nesting_share is COMPLEXITY evidence');
    }

    public function test_unsupported_wins_over_missing_which_wins_over_insufficient(): void
    {
        [$dimensions, $evidence] = $this->handBuilt([
            'STRUCTURE.long_function_share' => ['status' => 'INSUFFICIENT_EVIDENCE', 'score' => null],
            'STRUCTURE.long_parameter_list_share' => ['status' => 'UNSUPPORTED', 'score' => null],
            'COMPLEXITY.deep_nesting_share' => null,
        ]);
        $this->assertSame('UNSUPPORTED', $this->byKey($this->assess($dimensions, $evidence))['FUNCTION_DESIGN']['status']);

        [$dimensions, $evidence] = $this->handBuilt([
            'STRUCTURE.long_function_share' => ['status' => 'INSUFFICIENT_EVIDENCE', 'score' => null],
            'COMPLEXITY.deep_nesting_share' => null,
        ]);
        $this->assertSame('MISSING', $this->byKey($this->assess($dimensions, $evidence))['FUNCTION_DESIGN']['status']);
    }

    public function test_project_size_changes_evidence_quality_not_scores(): void
    {
        [$small, $smallEvidence] = $this->dna();
        // Ten times the code with the same proportions.
        [$large, $largeEvidence] = $this->dna(
            ['files_analyzable' => 100, 'files_parsed' => 90, 'files_parse_error' => 10, 'functions_total' => 400, 'complexity_total' => 1600, 'complexity_over_threshold' => 20, 'types' => 100],
            ['structure/function-length' => 10],
        );
        $smallMatrix = $this->byKey($this->assess($small, $smallEvidence));
        $largeMatrix = $this->byKey($this->assess($large, $largeEvidence));

        foreach ($smallMatrix as $key => $competency) {
            $this->assertSame([$competency['score'], $competency['level']], [$largeMatrix[$key]['score'], $largeMatrix[$key]['level']], $key);
        }
        // More functions only raise evidence volume (capped at 50 functions).
        $this->assertSame(['0.9000', '0.9500'], [$smallMatrix['FUNCTION_DESIGN']['evidence_quality'], $largeMatrix['FUNCTION_DESIGN']['evidence_quality']]);
    }

    public function test_partially_supported_languages_are_reported_without_changing_scores(): void
    {
        [$dimensions, $evidence] = $this->dna();
        $plain = $this->byKey($this->assess($dimensions, $evidence, ['php']));
        $mixed = $this->assess($dimensions, $evidence, ['php', 'cpp', 'c']);
        $matrix = $this->byKey($mixed);

        $this->assertSame(['c', 'cpp', 'php'], $mixed->languages);
        $this->assertSame(['c', 'cpp'], array_column($matrix['COMPLEXITY_MANAGEMENT']['limitations'], 'language'));
        $this->assertStringContainsString('without a preprocessor', $matrix['COMPLEXITY_MANAGEMENT']['limitations'][0]['note']);
        $this->assertSame(['c', 'cpp'], array_column($matrix['FUNCTION_DESIGN']['limitations'], 'language'));
        $this->assertSame(['c', 'cpp'], array_column($matrix['CODE_HYGIENE']['limitations'], 'language'));
        $this->assertSame([], $matrix['TYPE_STRUCTURE']['limitations']);
        $this->assertSame([], $plain['COMPLEXITY_MANAGEMENT']['limitations']);
        foreach ($plain as $key => $competency) {
            $this->assertSame($competency['score'], $matrix[$key]['score'], $key);
        }
    }

    public function test_unknown_dna_scoring_versions_are_rejected(): void
    {
        [$dimensions, $evidence] = $this->dna();

        try {
            $this->assess($dimensions, $evidence, [], '1.0');
            $this->fail('accepted an unknown DNA scoring version');
        } catch (CompetencyException $e) {
            $this->assertSame(CompetencyFailure::DnaScoringVersionUnsupported, $e->failure);
        }
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>|null>, bool}>
     */
    public static function invalidDna(): iterable
    {
        yield 'unknown status' => [['CODE_HYGIENE.syntax_error_share' => ['status' => 'GOOD']], false];
        yield 'score above 1' => [['CODE_HYGIENE.syntax_error_share' => ['score' => '1.5000']], false];
        yield 'float score' => [['CODE_HYGIENE.syntax_error_share' => ['score' => 0.5]], false];
        yield 'available without score' => [['CODE_HYGIENE.syntax_error_share' => ['score' => null]], false];
        yield 'counts not a map' => [['CODE_HYGIENE.syntax_error_share' => ['numerator' => 'x']], false];
        yield 'no data quality' => [[], true];
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $components
     */
    #[DataProvider('invalidDna')]
    public function test_malformed_dna_is_rejected(array $components, bool $dropQuality): void
    {
        [$dimensions, $evidence] = $this->handBuilt($components);
        if ($dropQuality) {
            unset($evidence['data_quality']);
        }

        try {
            $this->assess($dimensions, $evidence);
            $this->fail('accepted malformed DNA');
        } catch (CompetencyException $e) {
            $this->assertSame(CompetencyFailure::DnaSnapshotInvalid, $e->failure);
        }
    }

    public function test_optional_evidence_is_skipped_and_its_weight_redistributed(): void
    {
        $base = CompetencySpecification::v1_0_0();
        $complexity = $base->competencies[0];
        $spec = new CompetencySpecification('9.9.9', ['1.0.0'], [
            new CompetencyDefinition($complexity->key, $complexity->description, [
                new EvidenceRule('COMPLEXITY', 'mean_cyclomatic_complexity', 6_000, true, 'r'),
                new EvidenceRule('COMPLEXITY', 'complex_function_share', 4_000, false, 'r'),
            ], []),
        ], $base->levels, $base->evidenceQualityWeights);
        [$dimensions, $evidence] = $this->handBuilt([
            'COMPLEXITY.mean_cyclomatic_complexity' => ['score' => '0.7000'],
            'COMPLEXITY.complex_function_share' => ['status' => 'UNSUPPORTED', 'score' => null],
        ]);

        $competency = (new CompetencyEngine)->assess($dimensions, $evidence, '1.0.0', [], $spec)->competencies[0];

        // Only the required evidence counts: 0.7 × 0.6 / 0.6.
        $this->assertSame(['ASSESSED', '0.7000', 'ESTABLISHED'], [$competency['status'], $competency['score'], $competency['level']]);
        $this->assertSame('0.5000', $competency['evidence_quality_terms']['evidence_availability']);
    }
}
