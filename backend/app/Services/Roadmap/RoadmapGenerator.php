<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

use App\Enums\Roadmap\RoadmapStepType;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Challenge\ChallengeCatalog;
use App\Services\Challenge\ChallengeSelectionException;
use App\Services\Challenge\ChallengeSelector;

/**
 * Generates a learning roadmap from the results of one skill gap snapshot
 * (docs/architecture/learning-roadmap-v1.md#generation). A pure function
 * of the results, the skill gap specification it was computed with, the
 * roadmap catalog, the rules and the challenge catalog: no clock, no
 * randomness, no network, no AI, and no project history.
 *
 * - The focus (DevelopmentFocusResolver) selects the tracks, in rank order.
 * - Every step of each selected track is copied, numbered in learning
 *   order across tracks.
 * - A CHALLENGE step names the Phase 16 challenge the challenge selector
 *   recommends for that gap with no history (the same pure function the
 *   challenge service uses). It is a reference to the existing catalog,
 *   never a copy of the challenge.
 *
 * The fingerprint covers everything a roadmap shows and is built from
 * values only: no IDs and no timestamps.
 */
final readonly class RoadmapGenerator
{
    public function __construct(
        private DevelopmentFocusResolver $resolver,
        private ChallengeSelector $selector,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $results  the snapshot's results (see DevelopmentFocusResolver)
     * @param  array{version: string, specification_fingerprint: string, target_profile: string, target_profile_version: string}  $skillGap
     *
     * @throws RoadmapGenerationException when no result is actionable
     */
    public function generate(array $results, array $skillGap, RoadmapCatalog $catalog, RoadmapRules $rules, ChallengeCatalog $challenges): RoadmapPlan
    {
        $focus = $this->resolver->resolve($results, $catalog, $rules);
        if ($focus->selected === []) {
            throw new RoadmapGenerationException(RoadmapGenerationException::NO_ACTIONABLE_GAPS);
        }

        $tracks = [];
        $steps = [];
        foreach ($focus->selected as $entry) {
            $track = $catalog->trackFor((string) $entry['competency_key']);
            if ($track === null) {
                throw new RoadmapGenerationException(RoadmapGenerationException::NO_ACTIONABLE_GAPS);
            }
            $tracks[] = [
                'position' => $entry['rank'],
                'key' => $track->key(),
                'version' => $track->version(),
                'competency_key' => $track->competency()->value,
                'title' => $track->document['title'],
                'description' => $track->document['description'],
                'objective' => $track->document['objective'],
                'estimated_minutes' => $track->document['estimated_minutes'],
                'fingerprint' => $track->fingerprint(),
            ];
            foreach ($track->steps() as $i => $step) {
                $steps[] = [
                    'position' => count($steps) + 1,
                    'track_position' => $entry['rank'],
                    'step_position' => $i + 1,
                    'track_key' => $track->key(),
                    'competency_key' => $track->competency()->value,
                    'key' => $step['key'],
                    'type' => $step['type'],
                    'title' => $step['title'],
                    'description' => $step['description'],
                    'objective' => $step['objective'],
                    'estimated_minutes' => $step['estimated_minutes'],
                    'prerequisites' => $step['prerequisites'],
                    'challenge' => $step['type'] === RoadmapStepType::Challenge->value ? $this->challenge($entry, $challenges) : null,
                ];
            }
        }

        $content = [
            'roadmap_version' => $catalog->version(),
            'rules_version' => $rules->version,
            'catalog_fingerprint' => $catalog->fingerprint(),
            'rules_fingerprint' => $rules->fingerprint(),
            'challenge_catalog' => ['version' => $challenges->version(), 'fingerprint' => $challenges->fingerprint()],
            'skill_gap' => $skillGap,
            'focus' => $focus->toArray(),
            'tracks' => $tracks,
            'steps' => $steps,
        ];

        return new RoadmapPlan($focus, $tracks, $steps, $content, self::fingerprint($content));
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public static function fingerprint(array $content): string
    {
        return CanonicalJson::hash(json_decode((string) json_encode($content, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR));
    }

    /**
     * The recommended challenge for the gap, or null when the challenge
     * catalog has none for it.
     *
     * @param  array<string, mixed>  $entry
     * @return array{key: string, version: string, title: string, difficulty: string}|null
     */
    private function challenge(array $entry, ChallengeCatalog $challenges): ?array
    {
        try {
            $definition = $this->selector->select([$entry], $challenges, [], [], (string) $entry['competency_key'])->definition;
        } catch (ChallengeSelectionException) {
            return null;
        }

        return [
            'key' => $definition->key(),
            'version' => $definition->version(),
            'title' => (string) $definition->document['title'],
            'difficulty' => $definition->difficulty()->value,
        ];
    }
}
