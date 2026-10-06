<?php

declare(strict_types=1);

namespace Tests\Unit\Analyzer;

use App\Services\Analyzer\JsonSchemaValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The analyzer client validates responses against the published schemas
 * (packages/api-contracts/analyzer/v1, mounted at /var/www/contracts).
 */
final class JsonSchemaValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(string $name): array
    {
        return json_decode((string) file_get_contents('/var/www/contracts/analyzer/v1/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }

    private function fixture(string $name): stdClass
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/analyzer/'.$name), false, 512, JSON_THROW_ON_ERROR);
    }

    public function test_real_analyzer_results_validate_against_their_schemas(): void
    {
        $validator = new JsonSchemaValidator;
        $this->assertSame([], $validator->validate($this->fixture('foundation-response.json'), $this->schema('foundation-result.schema.json')));
        $this->assertSame([], $validator->validate($this->fixture('static-analysis-response.json'), $this->schema('static-analysis-result.schema.json')));
        // A result never validates as the other result type.
        $this->assertNotSame([], $validator->validate($this->fixture('static-analysis-response.json'), $this->schema('foundation-result.schema.json')));
        $this->assertNotSame([], $validator->validate($this->fixture('foundation-response.json'), $this->schema('static-analysis-result.schema.json')));
    }

    public function test_violations_are_reported_by_path_without_values(): void
    {
        $validator = new JsonSchemaValidator;
        $schema = $this->schema('static-analysis-result.schema.json');

        $mutations = [
            fn (stdClass $r) => $r->result_hash = 'NOT-A-HASH',
            fn (stdClass $r) => $r->versions->ir = '9.9',
            fn (stdClass $r) => $r->extra = 1,
            fn (stdClass $r) => $r->ir->files[1]->structure->functions[0]->complexity = 0,
            fn (stdClass $r) => $r->ir->files[1]->structure->types[0]->name = 'evil(); drop',
            fn (stdClass $r) => $r->findings->items[0]->message = "line\nbreak",
            fn (stdClass $r) => $r->metrics->overall->complexity_avg = 'high',
            function (stdClass $r): void {
                unset($r->source);
            },
        ];
        foreach ($mutations as $index => $mutate) {
            $result = $this->fixture('static-analysis-response.json');
            $mutate($result);
            $errors = $validator->validate($result, $schema);
            $this->assertNotSame([], $errors, "mutation {$index}");
            foreach ($errors as $error) {
                $this->assertStringNotContainsString('evil', $error);
                $this->assertStringStartsWith('$', $error);
            }
        }
    }

    public function test_unknown_keywords_fail_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new JsonSchemaValidator)->validate(new stdClass, ['type' => 'object', 'oneOf' => []]);
    }
}
