<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

use App\Enums\SkillGap\GapPriority;
use App\Enums\SkillGap\SkillGapStatus;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Challenge\ChallengeSelector;
use InvalidArgumentException;

/**
 * The deterministic roadmap generation rules
 * (docs/architecture/learning-roadmap-v1.md#rules). Versioned and
 * fingerprinted: changing any rule needs a new version, and existing
 * roadmaps are never reinterpreted.
 */
final readonly class RoadmapRules
{
    public const VERSION_1_0_0 = '1.0.0';

    /** @var list<string> */
    public const VERSIONS = [self::VERSION_1_0_0];

    /**
     * @param  list<string>  $actionableStatuses  skill gap statuses that are a learning need
     * @param  array<string, int>  $priorityRank  higher ranks first
     * @param  list<string>  $ordering  the focus ordering criteria, in order
     */
    public function __construct(
        public string $version,
        public array $actionableStatuses,
        public array $priorityRank,
        public array $ordering,
        public int $maxTracks,
        public int $maxStepsPerTrack,
        public string $challengeSelectionVersion,
    ) {}

    public static function forVersion(string $version): self
    {
        return match ($version) {
            self::VERSION_1_0_0 => self::v1_0_0(),
            default => throw new InvalidArgumentException("Unknown roadmap rules version: {$version}"),
        };
    }

    /**
     * Rules 1.0.0: only GAP results are actionable; focus ordered by
     * priority, raw gap, evidence quality, then competency key; at most
     * three tracks of at most eight steps.
     */
    public static function v1_0_0(): self
    {
        return new self(
            version: self::VERSION_1_0_0,
            actionableStatuses: [SkillGapStatus::Gap->value],
            priorityRank: [GapPriority::High->value => 3, GapPriority::Medium->value => 2, GapPriority::Low->value => 1],
            ordering: ['priority desc', 'raw_gap desc', 'evidence_quality desc (missing last)', 'competency_key asc'],
            maxTracks: 3,
            maxStepsPerTrack: 8,
            challengeSelectionVersion: ChallengeSelector::VERSION,
        );
    }

    /**
     * @return array<string, mixed> the complete definition, in a fixed order
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'actionable_statuses' => $this->actionableStatuses,
            'priority_rank' => $this->priorityRank,
            'ordering' => $this->ordering,
            'max_tracks' => $this->maxTracks,
            'max_steps_per_track' => $this->maxStepsPerTrack,
            'challenge_selection_version' => $this->challengeSelectionVersion,
        ];
    }

    public function fingerprint(): string
    {
        return CanonicalJson::hash(json_decode((string) json_encode($this->toArray(), JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR));
    }
}
