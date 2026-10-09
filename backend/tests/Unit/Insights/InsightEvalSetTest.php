<?php

declare(strict_types=1);

namespace Tests\Unit\Insights;

use App\Services\Analyzer\JsonSchemaValidator;
use App\Services\Insights\InsightEvalSet;
use App\Services\Insights\InsightException;
use App\Services\Insights\InsightResponseValidator;
use App\Services\Insights\InsightSpecification;
use Tests\TestCase;

/**
 * Validator correctness on the versioned AI evaluation set (Phase 30,
 * resources/ai-eval/insight-eval-v1.json). Every case's fixed output must get
 * exactly the expected decision. This measures the validator, not a model:
 * model usefulness is measured by `php artisan ai:eval` against a real runtime.
 */
final class InsightEvalSetTest extends TestCase
{
    /** The scenarios a V1 evaluation must cover. */
    private const REQUIRED = [
        'strong-evidence', 'insufficient-evidence', 'conflicting-evidence', 'no-growth-claimed-as-growth', 'genuine-growth',
        'failed-challenge', 'passed-challenge', 'locked-step', 'prompt-injection', 'malformed-output',
    ];

    public function test_every_case_gets_exactly_the_expected_decision(): void
    {
        $validator = new InsightResponseValidator(new JsonSchemaValidator);
        $spec = new InsightSpecification;
        $cases = InsightEvalSet::cases();
        $this->assertSame([], array_diff(self::REQUIRED, array_column($cases, 'id')), 'the required scenarios are covered');
        $this->assertSame(count($cases), count(array_unique(array_column($cases, 'id'))));

        foreach ($cases as $case) {
            $rule = null;
            try {
                $validator->validate($case['output'], $case['input'], $spec, 16384);
                $outcome = 'accept';
            } catch (InsightException $e) {
                $outcome = 'reject';
                $rule = $e->detail;
            }
            $this->assertSame($case['expected']['outcome'], $outcome, "{$case['id']}: {$case['scenario']} (rule: {$rule})");
            if (isset($case['expected']['rule'])) {
                $this->assertSame($case['expected']['rule'], $rule, $case['id']);
            }
        }
    }

    public function test_the_evidence_is_built_like_real_evidence_and_fits_the_default_context(): void
    {
        $spec = new InsightSpecification;
        foreach (InsightEvalSet::cases() as $case) {
            $this->assertNotSame([], $case['input']->evidenceIds(), $case['id']);
            $this->assertLessThan(8192, (int) (strlen($spec->systemPrompt($case['kind']).$spec->userMessage($case['input'])) / 3) + 2000, $case['id']);
        }
    }
}
