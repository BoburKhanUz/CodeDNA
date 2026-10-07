<?php

declare(strict_types=1);

namespace Tests\Unit\Roadmap;

use App\Enums\Roadmap\RoadmapStepType;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Roadmap\RoadmapCatalog;
use App\Services\Roadmap\RoadmapRules;
use App\Services\Roadmap\RoadmapTrackData;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The server-owned roadmap catalog: frozen content, and every structural
 * rule enforced at load.
 */
final class RoadmapCatalogTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            array_map('unlink', glob($directory.'/*') ?: []);
            @rmdir($directory);
        }
        parent::tearDown();
    }

    private function catalog(): RoadmapCatalog
    {
        return RoadmapCatalog::forVersion('1.0.0', ChallengeCatalog::forVersion('1.0.0'));
    }

    /**
     * @param  array<string, array<string, mixed>>  $documents  file key => document
     */
    private function directory(array $documents): string
    {
        $directory = sys_get_temp_dir().'/roadmap-'.Str::random(8);
        mkdir($directory);
        $this->directories[] = $directory;
        foreach ($documents as $file => $document) {
            file_put_contents("{$directory}/{$file}.json", json_encode($document));
        }

        return $directory;
    }

    /**
     * @return array<string, mixed>
     */
    private static function track(string $key = 'FUNCTION_DESIGN'): array
    {
        return json_decode((string) file_get_contents(resource_path("roadmaps/v1/{$key}.json")), true);
    }

    public function test_catalog_1_0_0_is_frozen(): void
    {
        $catalog = $this->catalog();

        $this->assertSame('937b1e7f209819a06377d009112a6f003ac6c462ddab6b9a3ae804b75e759e63', $catalog->fingerprint());
        $this->assertSame(
            ['CODE_HYGIENE' => 6, 'COMPLEXITY_MANAGEMENT' => 7, 'FUNCTION_DESIGN' => 8, 'TYPE_STRUCTURE' => 6],
            array_combine(
                array_map(fn (RoadmapTrackData $t): string => $t->key(), $catalog->tracks()),
                array_map(fn (RoadmapTrackData $t): int => count($t->steps()), $catalog->tracks()),
            ),
        );
        foreach ($catalog->tracks() as $track) {
            $this->assertSame('1.0.0', $track->version());
            $this->assertSame($track->key(), $track->competency()->value);
        }
    }

    /**
     * Only the four measurable competencies have tracks: no testing,
     * security, naming, performance, documentation or architecture tracks.
     */
    public function test_only_measurable_competencies_have_tracks(): void
    {
        $this->assertSame(
            ['CODE_HYGIENE', 'COMPLEXITY_MANAGEMENT', 'FUNCTION_DESIGN', 'TYPE_STRUCTURE'],
            array_map(fn (RoadmapTrackData $t): string => $t->key(), $this->catalog()->tracks()),
        );
        $this->assertNull($this->catalog()->trackFor('TESTING'));
    }

    public function test_every_track_reads_first_practises_on_a_challenge_and_ends_with_reassessment(): void
    {
        foreach ($this->catalog()->tracks() as $track) {
            $types = array_column($track->steps(), 'type');
            $this->assertSame(RoadmapStepType::Read->value, $types[0], $track->key());
            $this->assertSame(RoadmapStepType::Reassess->value, end($types), $track->key());
            $this->assertCount(1, array_keys($types, RoadmapStepType::Challenge->value, true), $track->key());
        }
    }

    public function test_content_holds_no_links_or_commands(): void
    {
        foreach ($this->catalog()->tracks() as $track) {
            $json = (string) json_encode($track->document);
            $this->assertDoesNotMatchRegularExpression('#https?://|www\.|<script|\$\(|`#i', $json, $track->key());
        }
    }

    public function test_an_unknown_version_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RoadmapCatalog::forVersion('2.0.0', ChallengeCatalog::forVersion('1.0.0'));
    }

    /**
     * The rules' step limit applies at load: the 8-step track fits, a
     * 9-step track does not.
     */
    public function test_the_step_limit_comes_from_the_rules(): void
    {
        $this->assertSame(8, max(array_map(fn (RoadmapTrackData $t): int => count($t->steps()), $this->catalog()->tracks())));

        $document = self::track();
        $extra = $document['steps'][1];
        $extra['key'] = 'fd-extra';
        array_splice($document['steps'], 2, 0, [$extra]);
        $document['steps'][8]['prerequisites'] = array_column(array_slice($document['steps'], 0, 8), 'key');
        $document['estimated_minutes'] += $extra['estimated_minutes'];

        $this->expectExceptionMessage('a track has at most 8 steps');
        new RoadmapCatalog('1.0.0', ChallengeCatalog::forVersion('1.0.0'), RoadmapRules::v1_0_0(), $this->directory(['FUNCTION_DESIGN' => $document]));
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function invalidTracks(): array
    {
        return [
            'unknown field' => [fn (array $d) => $d + ['url' => 'x'], 'Invalid roadmap track'],
            'unknown step type' => [function (array $d) {
                $d['steps'][1]['type'] = 'COURSE';

                return $d;
            }, 'Invalid roadmap track'],
            'unsupported competency' => [fn (array $d) => ['competency' => 'TESTING'] + $d, 'Invalid roadmap track'],
            'key differs from competency' => [fn (array $d) => ['competency' => 'CODE_HYGIENE'] + $d, 'the key must be its competency'],
            'wrong step prefix' => [function (array $d) {
                $d['steps'][1]['key'] = 'cm-identify';

                return $d;
            }, 'must start with fd-'],
            'duplicate step key' => [function (array $d) {
                $d['steps'][2]['key'] = $d['steps'][1]['key'];

                return $d;
            }, 'is not unique'],
            'prerequisite is a later step' => [function (array $d) {
                $d['steps'][1]['prerequisites'] = ['fd-reduce-length'];

                return $d;
            }, 'which is not an earlier step'],
            'reassess not last' => [function (array $d) {
                $d['steps'][6]['type'] = 'REASSESS';
                $d['steps'][6]['prerequisites'] = array_column(array_slice($d['steps'], 0, 6), 'key');

                return $d;
            }, 'the REASSESS step must be the last step'],
            'reassess skips a step' => [function (array $d) {
                array_pop($d['steps'][7]['prerequisites']);

                return $d;
            }, 'must depend on every other step'],
            'no reassess' => [function (array $d) {
                $d['steps'][7]['type'] = 'PRACTICE';

                return $d;
            }, 'exactly one REASSESS step'],
            'two challenges' => [function (array $d) {
                $d['steps'][5]['type'] = 'CHALLENGE';

                return $d;
            }, 'exactly one CHALLENGE step'],
            'no challenge' => [function (array $d) {
                $d['steps'][6]['type'] = 'PRACTICE';

                return $d;
            }, 'exactly one CHALLENGE step'],
            'estimate not the sum' => [fn (array $d) => ['estimated_minutes' => $d['estimated_minutes'] + 1] + $d, 'the estimate must be the sum'],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    #[DataProvider('invalidTracks')]
    public function test_invalid_tracks_are_refused_at_load(callable $change, string $message): void
    {
        $directory = $this->directory(['FUNCTION_DESIGN' => $change(self::track())]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        new RoadmapCatalog('1.0.0', ChallengeCatalog::forVersion('1.0.0'), RoadmapRules::v1_0_0(), $directory);
    }

    public function test_the_file_must_be_named_after_its_key(): void
    {
        $this->expectExceptionMessage('the file name must be the key');
        new RoadmapCatalog('1.0.0', ChallengeCatalog::forVersion('1.0.0'), RoadmapRules::v1_0_0(), $this->directory(['OTHER' => self::track()]));
    }

    /**
     * A track needs a Phase 16 challenge for its competency: the roadmap
     * references the existing challenge catalog and never duplicates it.
     */
    public function test_a_track_without_a_challenge_for_its_competency_is_refused(): void
    {
        $challenges = sys_get_temp_dir().'/challenges-'.Str::random(8);
        mkdir($challenges);
        $this->directories[] = $challenges;
        copy(resource_path('challenges/v1/CODE_HYGIENE_001.json'), "{$challenges}/CODE_HYGIENE_001.json");

        $this->expectExceptionMessage('the challenge catalog has no challenge for this competency');
        new RoadmapCatalog('1.0.0', new ChallengeCatalog('1.0.0', $challenges), RoadmapRules::v1_0_0(), $this->directory(['FUNCTION_DESIGN' => self::track()]));
    }

    public function test_an_empty_catalog_is_refused(): void
    {
        $this->expectExceptionMessage('The roadmap catalog is empty.');
        new RoadmapCatalog('1.0.0', ChallengeCatalog::forVersion('1.0.0'), RoadmapRules::v1_0_0(), $this->directory([]));
    }

    public function test_changing_any_content_changes_the_fingerprint(): void
    {
        $original = $this->catalog()->fingerprint();
        $document = self::track();
        $document['steps'][0]['title'] .= '.';
        $changed = new RoadmapCatalog('1.0.0', ChallengeCatalog::forVersion('1.0.0'), RoadmapRules::v1_0_0(), $this->directory([
            'FUNCTION_DESIGN' => $document, 'CODE_HYGIENE' => self::track('CODE_HYGIENE'),
            'COMPLEXITY_MANAGEMENT' => self::track('COMPLEXITY_MANAGEMENT'), 'TYPE_STRUCTURE' => self::track('TYPE_STRUCTURE'),
        ]));

        $this->assertNotSame($original, $changed->fingerprint());
    }
}
