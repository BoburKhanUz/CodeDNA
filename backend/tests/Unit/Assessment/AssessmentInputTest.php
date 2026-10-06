<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\AssessmentInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssessmentInputTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function numbers(): iterable
    {
        yield 'four places' => ['0.9000', '0.9'];
        yield 'two places' => ['0.90', '0.9'];
        yield 'short' => ['0.9', '0.9'];
        yield 'zero decimal' => ['0.0000', '0'];
        yield 'zero' => ['0', '0'];
        yield 'small' => ['0.0500', '0.05'];
        yield 'integer' => ['40', '40'];
        yield 'leading zero' => ['040', '40'];
        yield 'whole decimal' => ['10.0', '10'];
        yield 'hundred' => ['100.00', '100'];
        yield 'mixed' => ['2.5000', '2.5'];
    }

    #[DataProvider('numbers')]
    public function test_numbers_are_normalized(string $number, string $normalized): void
    {
        $this->assertSame($normalized, AssessmentInput::normalizeNumber($number));
    }

    public function test_numbers_are_collected_from_facts_only(): void
    {
        $input = new AssessmentInput([], ['evidence' => [
            ['id' => 'quality:data', 'label' => 'Label 7', 'description' => 'Above 10 lines', 'facts' => ['score' => '0.6000', 'files' => 9, 'flag' => true, 'none' => null, 'list' => ['0.25']]],
            'not an item',
        ]]);

        $this->assertSame(['0.6' => true, '9' => true, '0.25' => true], $input->numbers());
        $this->assertSame(['quality:data'], $input->evidenceIds());
        $this->assertNull($input->evidenceItem('gap:X'));
    }

    public function test_the_fingerprint_covers_lineage_and_payload_but_only_the_payload_is_sent(): void
    {
        $a = new AssessmentInput(['skill_gap_snapshot_id' => 'a'], ['evidence' => []]);
        $b = new AssessmentInput(['skill_gap_snapshot_id' => 'b'], ['evidence' => []]);
        $c = new AssessmentInput(['skill_gap_snapshot_id' => 'a'], ['evidence' => [], 'x' => 1]);

        $this->assertSame($a->canonicalJson(), $b->canonicalJson());
        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
        $this->assertNotSame($a->fingerprint(), $c->fingerprint());
        $this->assertSame(hash('sha256', '{"lineage":{"skill_gap_snapshot_id":"a"},"payload":{"evidence":[]}}'), $a->fingerprint());
        $this->assertEquals($a, AssessmentInput::fromStored($a->toStored()));
        $this->assertEquals(new AssessmentInput([], []), AssessmentInput::fromStored(['lineage' => 'x']));
    }
}
