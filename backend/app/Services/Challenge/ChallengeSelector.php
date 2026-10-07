<?php

declare(strict_types=1);

namespace App\Services\Challenge;

use App\Enums\Challenge\ChallengeDifficulty;
use App\Enums\SkillGap\GapPriority;
use App\Enums\SkillGap\SkillGapStatus;
use App\Services\Dna\FixedPoint;

/**
 * Chooses a challenge for a skill gap snapshot
 * (docs/architecture/coding-challenges-v1.md#selection). A pure function:
 * same gaps, catalog, history and request give the same selection. No
 * randomness, no clock, no AI.
 *
 *  1. Eligible gaps: results with status GAP whose competency has a
 *     category in the catalog, ordered by priority (HIGH, MEDIUM, LOW),
 *     then raw gap (largest first), then competency key.
 *  2. The requested competency, or the eligible gaps in that order.
 *  3. Candidates: the newest version of each definition of the category in
 *     an executable language, minus definitions already assigned for this
 *     snapshot.
 *  4. Candidate order: not passed before in this project, then the
 *     difficulty preferred for the gap's priority (HIGH and MEDIUM:
 *     BEGINNER, LOW: INTERMEDIATE), then the nearest difficulty, then key.
 *  5. The first gap with a candidate wins; its first candidate is selected.
 */
final class ChallengeSelector
{
    public const VERSION = 'challenge-selection/1.0.0';

    private const PRIORITY_RANK = ['HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];

    private const DIFFICULTY_ORDER = ['BEGINNER', 'INTERMEDIATE', 'ADVANCED'];

    /**
     * @param  list<array<string, mixed>>  $gaps  skill gap results: competency_key, status, priority, raw_gap, current_score, target_score, priority_capped
     * @param  list<string>  $assigned  definition keys already assigned for this snapshot
     * @param  list<string>  $passed  definition keys already passed in this project
     *
     * @throws ChallengeSelectionException
     */
    public function select(array $gaps, ChallengeCatalog $catalog, array $assigned, array $passed, ?string $competency = null): ChallengeSelection
    {
        $categories = array_unique(array_map(fn (ChallengeDefinitionData $d): string => $d->category()->value, $catalog->definitions()));
        $eligible = array_values(array_filter(
            $gaps,
            fn (array $gap): bool => ($gap['status'] ?? null) === SkillGapStatus::Gap->value && in_array($gap['competency_key'] ?? null, $categories, true),
        ));
        usort($eligible, fn (array $a, array $b): int => [self::PRIORITY_RANK[$b['priority']] ?? 0, FixedPoint::parse((string) $b['raw_gap']), $a['competency_key']]
            <=> [self::PRIORITY_RANK[$a['priority']] ?? 0, FixedPoint::parse((string) $a['raw_gap']), $b['competency_key']]);
        $order = array_column($eligible, 'competency_key');

        if ($competency !== null) {
            $requested = array_values(array_filter($eligible, fn (array $gap): bool => $gap['competency_key'] === $competency));
            if ($requested === []) {
                throw new ChallengeSelectionException(ChallengeSelectionException::NO_ELIGIBLE_GAP);
            }
            $candidates = $requested;
        } elseif ($eligible === []) {
            throw new ChallengeSelectionException(ChallengeSelectionException::NO_ELIGIBLE_GAP);
        } else {
            $candidates = $eligible;
        }

        foreach ($candidates as $gap) {
            $definitions = $this->candidates($catalog, $gap, $assigned, $passed);
            if ($definitions === []) {
                continue;
            }
            $definition = $definitions[0];
            $rank = array_search($gap['competency_key'], $order, true) + 1;
            $preferred = $this->preferredDifficulty((string) $gap['priority']);

            return new ChallengeSelection($definition, $gap['competency_key'], [
                'selection_version' => self::VERSION,
                'catalog_version' => $catalog->version(),
                'catalog_fingerprint' => $catalog->fingerprint(),
                'rule' => match (true) {
                    $competency !== null => 'REQUESTED_COMPETENCY',
                    $rank === 1 => 'TOP_PRIORITY_GAP',
                    default => 'NEXT_ELIGIBLE_GAP',
                },
                'requested_competency' => $competency,
                'eligible_gaps' => $order,
                'gap_rank' => $rank,
                'gap' => [
                    'competency_key' => $gap['competency_key'],
                    'status' => $gap['status'],
                    'priority' => $gap['priority'],
                    'priority_capped' => $gap['priority_capped'] ?? null,
                    'raw_gap' => $gap['raw_gap'],
                    'current_score' => $gap['current_score'] ?? null,
                    'target_score' => $gap['target_score'] ?? null,
                ],
                'challenge_category' => $definition->category()->value,
                'preferred_difficulty' => $preferred->value,
                'selected_difficulty' => $definition->difficulty()->value,
                'language' => $definition->language(),
                'language_rule' => 'ONLY_EXECUTABLE_LANGUAGE',
                'excluded_definitions' => array_values(array_unique($assigned)),
                'previously_passed' => array_values(array_unique($passed)),
                'challenge_definition' => $definition->key(),
                'challenge_version' => $definition->version(),
            ]);
        }

        throw new ChallengeSelectionException(ChallengeSelectionException::NONE_AVAILABLE);
    }

    public function preferredDifficulty(string $priority): ChallengeDifficulty
    {
        return $priority === GapPriority::Low->value ? ChallengeDifficulty::Intermediate : ChallengeDifficulty::Beginner;
    }

    /**
     * @param  array<string, mixed>  $gap
     * @param  list<string>  $assigned
     * @param  list<string>  $passed
     * @return list<ChallengeDefinitionData>
     */
    private function candidates(ChallengeCatalog $catalog, array $gap, array $assigned, array $passed): array
    {
        $newest = [];
        foreach ($catalog->definitions() as $definition) {
            if ($definition->category()->value !== $gap['competency_key']
                || ! in_array($definition->language(), ChallengeCatalog::EXECUTABLE_LANGUAGES, true)) {
                continue;
            }
            $current = $newest[$definition->key()] ?? null;
            if ($current === null || version_compare($definition->version(), $current->version(), '>')) {
                $newest[$definition->key()] = $definition;
            }
        }
        $available = array_values(array_filter($newest, fn (ChallengeDefinitionData $d): bool => ! in_array($d->key(), $assigned, true)));
        $preferred = array_search($this->preferredDifficulty((string) $gap['priority'])->value, self::DIFFICULTY_ORDER, true);
        usort($available, function (ChallengeDefinitionData $a, ChallengeDefinitionData $b) use ($passed, $preferred): int {
            $distance = fn (ChallengeDefinitionData $d): int => abs(array_search($d->difficulty()->value, self::DIFFICULTY_ORDER, true) - $preferred);

            return [in_array($a->key(), $passed, true), $distance($a), $a->key()] <=> [in_array($b->key(), $passed, true), $distance($b), $b->key()];
        });

        return $available;
    }
}
