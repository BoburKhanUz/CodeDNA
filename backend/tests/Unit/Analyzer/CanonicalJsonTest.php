<?php

declare(strict_types=1);

namespace Tests\Unit\Analyzer;

use App\Services\Analyzer\CanonicalJson;
use PHPUnit\Framework\TestCase;

/**
 * Laravel recomputes the analyzer's result_hash, so its canonical JSON must
 * equal Python's json.dumps(sort_keys=True, separators=(",", ":"),
 * ensure_ascii=False) byte for byte. Expectations come from Python.
 */
final class CanonicalJsonTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/analyzer/'.$name);
    }

    public function test_floats_are_formatted_like_python_repr(): void
    {
        $cases = json_decode($this->fixture('python-floats.json'), false)->cases;
        $this->assertGreaterThan(3000, count($cases));
        foreach ($cases as $case) {
            $value = json_decode($case->json);
            $this->assertSame($case->repr, CanonicalJson::float((float) $value), $case->json);
        }
    }

    public function test_real_analyzer_results_hash_to_their_result_hash(): void
    {
        foreach (['foundation-response.json', 'static-analysis-response.json'] as $file) {
            $result = json_decode($this->fixture($file), false, 512, JSON_THROW_ON_ERROR);
            $expected = $result->result_hash;
            unset($result->request_id, $result->diagnostics, $result->result_hash);
            $this->assertSame($expected, CanonicalJson::hash($result), $file);
        }
    }

    public function test_canonical_form(): void
    {
        $value = json_decode('{"b":[1,2.5,{"z":null,"a":true}],"a":"é/ \"\n","é":{},"A":[],"10":1,"9":-0.0}', false);

        // Keys by code point ("10" < "9" < "A" < "a" < "b" < "é"), no spaces,
        // unescaped Unicode, slashes and line separators, -0.0 and {} kept.
        $this->assertSame(
            '{"10":1,"9":-0.0,"A":[],"a":"é/'."\u{2028}".'\"\n","b":[1,2.5,{"a":true,"z":null}],"é":{}}',
            CanonicalJson::encode($value),
        );
    }

    public function test_control_characters_are_escaped_like_python(): void
    {
        // json.dumps("\x00\x08\x0c\x1f\x7f", ensure_ascii=False)
        $this->assertSame('"\u0000\b\f\u001f'."\x7f".'"', CanonicalJson::encode("\x00\x08\x0c\x1f\x7f"));
    }

    public function test_nan_and_infinity_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CanonicalJson::encode(INF);
    }
}
