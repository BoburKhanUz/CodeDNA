<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

use App\Enums\Roadmap\RoadmapStepType;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Analyzer\JsonSchemaValidator;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\ChallengeDefinitionData;
use InvalidArgumentException;
use RuntimeException;

/**
 * The server-owned, versioned learning roadmap catalog
 * (docs/architecture/learning-roadmap-v1.md#catalog): one track per
 * measurable competency, loaded from resources/roadmaps/v1 and validated at
 * load. Clients can never supply or change roadmap content.
 *
 * Every track is checked against the roadmap-track/1 schema and these
 * rules: the file is named after the key, the key is the competency, step
 * keys are unique across the catalog and use the competency's prefix,
 * prerequisites name earlier steps of the same track, there is exactly one
 * CHALLENGE step (and the challenge catalog has a challenge for the
 * competency), the last step is the only REASSESS step and depends on all
 * others, the track's estimate is the sum of its steps, and no track has
 * more steps than the rules allow.
 */
final class RoadmapCatalog
{
    public const VERSION_1_0_0 = '1.0.0';

    /** @var list<string> */
    public const VERSIONS = [self::VERSION_1_0_0];

    private const STEP_PREFIX = [
        'COMPLEXITY_MANAGEMENT' => 'cm-',
        'FUNCTION_DESIGN' => 'fd-',
        'TYPE_STRUCTURE' => 'ts-',
        'CODE_HYGIENE' => 'ch-',
    ];

    /** @var array<string, RoadmapTrackData> keyed by competency */
    private array $tracks = [];

    public function __construct(
        private readonly string $version,
        ChallengeCatalog $challenges,
        RoadmapRules $rules,
        ?string $directory = null,
    ) {
        if (! in_array($version, self::VERSIONS, true)) {
            throw new InvalidArgumentException("Unknown roadmap catalog version: {$version}");
        }
        $directory ??= resource_path('roadmaps/v1');
        $schema = json_decode((string) file_get_contents(resource_path('roadmaps/roadmap-track-1.schema.json')), true, 64, JSON_THROW_ON_ERROR);
        $files = glob($directory.'/*.json') ?: [];
        sort($files);
        $stepKeys = [];
        foreach ($files as $file) {
            $raw = (string) file_get_contents($file);
            $errors = (new JsonSchemaValidator)->validate(json_decode($raw, false, 64, JSON_THROW_ON_ERROR), $schema);
            if ($errors !== []) {
                throw new RuntimeException('Invalid roadmap track '.basename($file).': '.implode('; ', $errors));
            }
            $track = new RoadmapTrackData(json_decode($raw, true, 64, JSON_THROW_ON_ERROR));
            $this->check($track, basename($file), $challenges, $rules, $stepKeys);
            $this->tracks[$track->key()] = $track;
        }
        if ($this->tracks === []) {
            throw new RuntimeException('The roadmap catalog is empty.');
        }
        ksort($this->tracks, SORT_STRING);
    }

    public static function forVersion(string $version, ChallengeCatalog $challenges, ?RoadmapRules $rules = null): self
    {
        return new self($version, $challenges, $rules ?? RoadmapRules::forVersion(RoadmapRules::VERSION_1_0_0));
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * @return list<RoadmapTrackData> sorted by key
     */
    public function tracks(): array
    {
        return array_values($this->tracks);
    }

    public function trackFor(string $competency): ?RoadmapTrackData
    {
        return $this->tracks[$competency] ?? null;
    }

    /**
     * SHA-256 over the catalog version and every track's key, version and
     * fingerprint (its full content).
     */
    public function fingerprint(): string
    {
        return CanonicalJson::hash(json_decode((string) json_encode([
            'version' => $this->version,
            'tracks' => array_map(fn (RoadmapTrackData $t): array => [$t->key(), $t->version(), $t->fingerprint()], $this->tracks()),
        ], JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, true>  $stepKeys  step keys seen so far (catalog-wide)
     */
    private function check(RoadmapTrackData $track, string $file, ChallengeCatalog $challenges, RoadmapRules $rules, array &$stepKeys): void
    {
        $fail = fn (string $why) => throw new RuntimeException("Invalid roadmap track {$file}: {$why}");
        if ($file !== $track->key().'.json') {
            $fail('the file name must be the key');
        }
        if ($track->key() !== $track->competency()->value) {
            $fail('the key must be its competency');
        }
        $steps = $track->steps();
        if (count($steps) > $rules->maxStepsPerTrack) {
            $fail("a track has at most {$rules->maxStepsPerTrack} steps");
        }
        $earlier = [];
        $types = array_column($steps, 'type');
        foreach ($steps as $i => $step) {
            if (! str_starts_with($step['key'], self::STEP_PREFIX[$track->key()])) {
                $fail("step {$step['key']} must start with ".self::STEP_PREFIX[$track->key()]);
            }
            if (isset($stepKeys[$step['key']])) {
                $fail("step key {$step['key']} is not unique");
            }
            foreach ($step['prerequisites'] as $prerequisite) {
                if (! in_array($prerequisite, $earlier, true)) {
                    $fail("step {$step['key']} depends on {$prerequisite}, which is not an earlier step of the track");
                }
            }
            if ($step['type'] === RoadmapStepType::Reassess->value) {
                if ($i !== count($steps) - 1) {
                    $fail('the REASSESS step must be the last step');
                }
                $others = array_column(array_slice($steps, 0, -1), 'key');
                if ($step['prerequisites'] !== $others) {
                    $fail('the REASSESS step must depend on every other step, in order');
                }
            }
            $stepKeys[$step['key']] = true;
            $earlier[] = $step['key'];
        }
        if (end($types) !== RoadmapStepType::Reassess->value || count(array_keys($types, RoadmapStepType::Reassess->value, true)) !== 1) {
            $fail('a track ends with exactly one REASSESS step');
        }
        if (count(array_keys($types, RoadmapStepType::Challenge->value, true)) !== 1) {
            $fail('a track has exactly one CHALLENGE step');
        }
        $practice = array_filter($challenges->definitions(), fn (ChallengeDefinitionData $d): bool => $d->category() === $track->competency());
        if ($practice === []) {
            $fail('the challenge catalog has no challenge for this competency');
        }
        if ($track->document['estimated_minutes'] !== array_sum(array_column($steps, 'estimated_minutes'))) {
            $fail('the estimate must be the sum of the steps');
        }
    }
}
