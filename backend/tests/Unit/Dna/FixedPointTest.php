<?php

declare(strict_types=1);

namespace Tests\Unit\Dna;

use App\Services\Dna\FixedPoint;
use App\Services\Dna\Normalizer;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixedPointTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function divisions(): iterable
    {
        yield 'exact' => [10_000, 4, 2_500];
        yield 'below half rounds down' => [14, 10, 1];
        yield 'exactly half rounds up' => [15, 10, 2];
        yield 'above half rounds up' => [16, 10, 2];
        yield 'one third' => [10_000, 3, 3_333];
        yield 'two thirds' => [20_000, 3, 6_667];
        yield 'zero' => [0, 7, 0];
    }

    #[DataProvider('divisions')]
    public function test_division_rounds_half_up(int $numerator, int $denominator, int $expected): void
    {
        $this->assertSame($expected, FixedPoint::divide($numerator, $denominator));
    }

    public function test_parse_and_format_are_exact_and_strict(): void
    {
        $this->assertSame(4_000, FixedPoint::parse('0.40'));
        $this->assertSame(100_000, FixedPoint::parse('10'));
        $this->assertSame(1, FixedPoint::parse('0.0001'));
        $this->assertSame('0.0000', FixedPoint::format(0));
        $this->assertSame('1.0000', FixedPoint::format(10_000));
        $this->assertSame('0.0125', FixedPoint::format(125));
        $this->assertSame('3.3333', FixedPoint::format(33_333));

        foreach (['0.12345', '-1', '1e3', '.5', '', ' 1'] as $invalid) {
            try {
                FixedPoint::parse($invalid);
                $this->fail("accepted {$invalid}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_operations_are_rejected(): void
    {
        foreach ([fn () => FixedPoint::divide(1, 0), fn () => FixedPoint::divide(-1, 3), fn () => FixedPoint::format(-1)] as $invalid) {
            try {
                $invalid();
                $this->fail('accepted an invalid operation');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(OverflowException::class);
        FixedPoint::multiply(PHP_INT_MAX, 2);
    }

    /**
     * best = 2.0, worst = 10.0 (mean cyclomatic complexity in 1.0.0).
     *
     * @return iterable<string, array{int, int, string}>
     */
    public static function boundaries(): iterable
    {
        yield 'zero' => [0, 1, '1.0000'];
        yield 'below best' => [19_999, 10_000, '1.0000'];
        yield 'exactly best' => [2, 1, '1.0000'];
        yield 'just above best (2.0001: 0.9999875)' => [20_001, 10_000, '1.0000'];
        yield 'above best (2.0008)' => [20_008, 10_000, '0.9999'];
        yield 'midpoint' => [6, 1, '0.5000'];
        yield 'just below worst (9.9999: 0.0000125)' => [99_999, 10_000, '0.0000'];
        yield 'below worst (9.9992)' => [99_992, 10_000, '0.0001'];
        yield 'exactly worst' => [10, 1, '0.0000'];
        yield 'just above worst' => [100_001, 10_000, '0.0000'];
        yield 'far above worst' => [1_000_000, 1, '0.0000'];
        yield 'exactly half a unit rounds up (2.0004: 0.99995)' => [20_004, 10_000, '1.0000'];
        yield 'below half a unit rounds down (2.0005: 0.9999375)' => [20_005, 10_000, '0.9999'];
    }

    #[DataProvider('boundaries')]
    public function test_lower_is_better_normalization_is_bounded_and_linear(int $numerator, int $denominator, string $expected): void
    {
        $this->assertSame($expected, FixedPoint::format(Normalizer::lowerIsBetter($numerator, $denominator, 20_000, 100_000)));
    }

    public function test_normalization_rounds_half_up_on_the_exact_rational_value(): void
    {
        // best 0, worst 0.20: value 1/3 of a unit above best scores (2000 - 1/3) / 2000 = 0.99983... -> 0.9998.
        $this->assertSame(9_998, Normalizer::lowerIsBetter(1, 30_000, 0, 2_000));
        // value 0.00005 exactly: (0.2 - 0.00005) / 0.2 = 0.99975 -> half up 0.9998.
        $this->assertSame(9_998, Normalizer::lowerIsBetter(1, 20_000, 0, 2_000));
    }

    public function test_normalization_rejects_invalid_thresholds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Normalizer::lowerIsBetter(1, 1, 2_000, 2_000);
    }
}
