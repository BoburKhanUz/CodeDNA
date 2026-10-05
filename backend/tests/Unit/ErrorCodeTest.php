<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Errors\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCodeTest extends TestCase
{
    public function test_every_code_maps_to_an_error_status_and_a_message(): void
    {
        foreach (ErrorCode::cases() as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]+(_[A-Z]+)*$/', $code->value);
            $this->assertGreaterThanOrEqual(400, $code->status());
            $this->assertLessThan(600, $code->status());
            $this->assertNotSame('', $code->defaultMessage());
        }
    }
}
