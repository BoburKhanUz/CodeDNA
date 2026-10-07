<?php

declare(strict_types=1);

namespace Tests\Unit\Challenge;

use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\ChallengeDefinitionData;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The server-owned catalog: frozen versions, integrity rules and the public
 * view that never contains hidden cases.
 */
final class ChallengeCatalogTest extends TestCase
{
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            array_map('unlink', glob($this->directory.'/*') ?: []);
            @rmdir($this->directory);
        }
        parent::tearDown();
    }

    /**
     * Catalog 1.0.0 is frozen. If this fails, a published definition was
     * edited: publish a new version of it instead.
     */
    public function test_catalog_1_0_0_is_frozen(): void
    {
        $catalog = ChallengeCatalog::forVersion('1.0.0');

        $this->assertSame(['1.0.0'], ChallengeCatalog::VERSIONS);
        $this->assertSame([
            'CODE_HYGIENE_001', 'COMPLEXITY_MANAGEMENT_001', 'COMPLEXITY_MANAGEMENT_002', 'FUNCTION_DESIGN_001', 'FUNCTION_DESIGN_002', 'TYPE_STRUCTURE_001',
        ], array_map(fn (ChallengeDefinitionData $d): string => $d->key(), $catalog->definitions()));
        $this->assertSame('23ede448f8b6a6ad97025e3b19745957dfda1875c76c483651fc6d6f6001ab9c', $catalog->fingerprint());
        foreach ($catalog->definitions() as $definition) {
            $this->assertSame('1.0.0', $definition->version());
            $this->assertSame('python', $definition->language());
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $definition->testSuiteFingerprint());
        }
    }

    public function test_only_supported_competencies_have_categories(): void
    {
        $categories = array_unique(array_map(fn (ChallengeDefinitionData $d): string => $d->category()->value, ChallengeCatalog::forVersion('1.0.0')->definitions()));
        sort($categories);

        $this->assertSame(['CODE_HYGIENE', 'COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE'], $categories);
    }

    public function test_an_unknown_version_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ChallengeCatalog::forVersion('9.9.9');
    }

    /**
     * Hidden cases (inputs and expected outputs) never leave the server.
     */
    public function test_the_public_view_contains_no_hidden_case(): void
    {
        foreach (ChallengeCatalog::forVersion('1.0.0')->definitions() as $definition) {
            $public = $definition->publicView();
            $encoded = (string) json_encode($public);
            $hidden = array_values(array_filter($definition->cases(), fn (array $c): bool => $c['visibility'] === 'HIDDEN'));

            $this->assertArrayNotHasKey('cases', $public);
            $this->assertSame(count($hidden), $public['hidden_case_count']);
            $this->assertSame(array_column(array_filter($definition->cases(), fn (array $c): bool => $c['visibility'] === 'VISIBLE'), 'id'), array_column($public['examples'], 'id'));
            foreach ($hidden as $case) {
                $this->assertStringNotContainsString('"'.$case['id'].'"', $encoded, $definition->key());
                $this->assertStringNotContainsString((string) json_encode($case['args']), $encoded, $definition->key().' '.$case['id']);
            }
        }
    }

    public function test_the_test_suite_fingerprint_covers_everything_that_decides_a_verdict(): void
    {
        $definition = ChallengeCatalog::forVersion('1.0.0')->find('CODE_HYGIENE_001', '1.0.0');
        $this->assertNotNull($definition);
        $document = $definition->document;

        $changed = fn (callable $change): string => (new ChallengeDefinitionData($change($document)))->testSuiteFingerprint();
        $base = $definition->testSuiteFingerprint();
        $this->assertNotSame($base, $changed(function (array $d): array {
            $d['cases'][0]['expected'] = ['x' => 'y'];

            return $d;
        }));
        $this->assertNotSame($base, $changed(function (array $d): array {
            $d['rules']['max_function_lines'] = 5;

            return $d;
        }));
        $this->assertNotSame($base, $changed(fn (array $d): array => ['entrypoint' => 'other'] + $d));
        $this->assertSame($base, $changed(fn (array $d): array => ['title' => 'A new title'] + $d), 'presentation is not part of the test suite');
        $this->assertNotSame($definition->fingerprint(), (new ChallengeDefinitionData(['title' => 'A new title'] + $document))->fingerprint());
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'unsupported language' => [fn (array $d): array => ['language' => 'bash'] + $d, 'Invalid challenge definition'];
        yield 'command field' => [fn (array $d): array => $d + ['command' => 'rm -rf /'], 'Invalid challenge definition'];
        yield 'unsupported competency' => [fn (array $d): array => ['key' => 'SECURITY_001', 'category' => 'SECURITY'] + $d, 'Invalid challenge definition'];
        yield 'key outside its category' => [fn (array $d): array => ['key' => 'FUNCTION_DESIGN_009'] + $d, 'Invalid challenge definition'];
        yield 'no hidden case' => [function (array $d): array {
            $d['cases'] = array_map(fn (array $c): array => ['visibility' => 'VISIBLE'] + $c, $d['cases']);

            return $d;
        }, 'visible and hidden'];
        yield 'duplicate case ids' => [function (array $d): array {
            $d['cases'][1]['id'] = $d['cases'][0]['id'];

            return $d;
        }, 'unique'];
        yield 'rule without a criterion' => [function (array $d): array {
            $d['rules']['max_function_lines'] = 10;

            return $d;
        }, 'not covered'];
        yield 'criterion with an unknown rule' => [function (array $d): array {
            $d['acceptance_criteria'][] = ['id' => 'AC9', 'description' => 'x', 'checks' => ['rule:max_function_lines']];

            return $d;
        }, 'undefined rule'];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    #[DataProvider('invalidDefinitions')]
    public function test_invalid_definitions_are_refused_at_load(callable $change, string $message): void
    {
        $document = json_decode((string) file_get_contents(resource_path('challenges/v1/CODE_HYGIENE_001.json')), true);
        $document = $change($document);
        $this->directory = sys_get_temp_dir().'/catalog-'.Str::random(8);
        mkdir($this->directory);
        file_put_contents($this->directory.'/'.$document['key'].'.json', json_encode($document));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        new ChallengeCatalog('1.0.0', $this->directory);
    }
}
