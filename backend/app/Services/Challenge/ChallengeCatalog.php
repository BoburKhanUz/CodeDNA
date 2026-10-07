<?php

declare(strict_types=1);

namespace App\Services\Challenge;

use App\Services\Analyzer\CanonicalJson;
use App\Services\Analyzer\JsonSchemaValidator;
use InvalidArgumentException;
use RuntimeException;

/**
 * The server-owned, versioned challenge catalog
 * (docs/architecture/coding-challenges-v1.md#catalog). Definitions are files
 * in resources/challenges/v{major}/, validated against
 * challenge-definition-1.schema.json and integrity rules on load. Clients
 * can never supply or change a definition.
 *
 * Catalog 1.0.0 is pinned by its fingerprint in tests: editing a published
 * definition fails the build; a changed exercise is a new version.
 */
final class ChallengeCatalog
{
    public const VERSION_1_0_0 = '1.0.0';

    public const VERSIONS = [self::VERSION_1_0_0];

    /** Languages with a safe, implemented execution path (the evaluator). */
    public const EXECUTABLE_LANGUAGES = ['python'];

    private const RULE_CHECKS = ['syntax_valid', 'max_function_complexity', 'max_function_nesting', 'max_function_lines',
        'max_function_parameters', 'max_class_lines', 'max_class_methods', 'min_classes'];

    /** @var array<string, ChallengeDefinitionData> keyed by "KEY@version" */
    private array $definitions = [];

    public function __construct(private readonly string $version, ?string $directory = null)
    {
        if (! in_array($version, self::VERSIONS, true)) {
            throw new InvalidArgumentException("Unknown challenge catalog version: {$version}");
        }
        $directory ??= resource_path('challenges/v1');
        $schema = json_decode((string) file_get_contents(resource_path('challenges/challenge-definition-1.schema.json')), true, 64, JSON_THROW_ON_ERROR);
        $files = glob($directory.'/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $raw = (string) file_get_contents($file);
            $errors = (new JsonSchemaValidator)->validate(json_decode($raw, false, 64, JSON_THROW_ON_ERROR), $schema);
            if ($errors !== []) {
                throw new RuntimeException('Invalid challenge definition '.basename($file).': '.implode('; ', $errors));
            }
            $definition = new ChallengeDefinitionData(json_decode($raw, true, 64, JSON_THROW_ON_ERROR));
            $this->check($definition, basename($file));
            $this->definitions[$definition->key().'@'.$definition->version()] = $definition;
        }
        if ($this->definitions === []) {
            throw new RuntimeException('The challenge catalog is empty.');
        }
    }

    public static function forVersion(string $version): self
    {
        return new self($version);
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * @return list<ChallengeDefinitionData> sorted by key, then version
     */
    public function definitions(): array
    {
        $definitions = $this->definitions;
        ksort($definitions, SORT_STRING);

        return array_values($definitions);
    }

    public function find(string $key, string $version): ?ChallengeDefinitionData
    {
        return $this->definitions[$key.'@'.$version] ?? null;
    }

    /**
     * SHA-256 over the catalog version and every definition's key, version
     * and fingerprint.
     */
    public function fingerprint(): string
    {
        return CanonicalJson::hash(json_decode((string) json_encode([
            'version' => $this->version,
            'definitions' => array_map(fn (ChallengeDefinitionData $d): array => [$d->key(), $d->version(), $d->fingerprint()], $this->definitions()),
        ], JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR));
    }

    private function check(ChallengeDefinitionData $definition, string $file): void
    {
        $fail = fn (string $why) => throw new RuntimeException("Invalid challenge definition {$file}: {$why}");
        if ($file !== $definition->key().'.json') {
            $fail('the file name must be the key');
        }
        if (! str_starts_with($definition->key(), $definition->category()->value.'_')) {
            $fail('the key must start with its category');
        }
        if (! in_array($definition->language(), self::EXECUTABLE_LANGUAGES, true)) {
            $fail('the language has no execution path');
        }
        $ids = array_column($definition->cases(), 'id');
        if (count($ids) !== count(array_unique($ids))) {
            $fail('case ids must be unique');
        }
        $visibilities = array_column($definition->cases(), 'visibility');
        if (! in_array(ChallengeDefinitionData::VISIBLE, $visibilities, true) || ! in_array(ChallengeDefinitionData::HIDDEN, $visibilities, true)) {
            $fail('there must be visible and hidden cases');
        }
        $criteria = array_column($definition->acceptanceCriteria(), 'id');
        if (count($criteria) !== count(array_unique($criteria))) {
            $fail('criterion ids must be unique');
        }
        $covered = [];
        foreach ($definition->acceptanceCriteria() as $criterion) {
            foreach ($criterion['checks'] as $check) {
                if (str_starts_with($check, 'rule:')) {
                    $rule = substr($check, 5);
                    if (! in_array($rule, self::RULE_CHECKS, true) || ! array_key_exists($rule, $definition->rules())) {
                        $fail("criterion {$criterion['id']} checks an undefined rule");
                    }
                }
                $covered[] = $check;
            }
        }
        foreach (array_keys($definition->rules()) as $rule) {
            if ($rule !== 'syntax_valid' && ! in_array("rule:{$rule}", $covered, true)) {
                $fail("rule {$rule} is not covered by an acceptance criterion");
            }
        }
        foreach (['tests:visible', 'tests:hidden'] as $tests) {
            if (! in_array($tests, $covered, true)) {
                $fail("{$tests} is not covered by an acceptance criterion");
            }
        }
    }
}
