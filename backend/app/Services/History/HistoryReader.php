<?php

declare(strict_types=1);

namespace App\Services\History;

use App\Enums\AnalysisRunStatus;
use App\Http\Pagination\CursorPage;
use App\Http\Pagination\KeysetPaginator;
use App\Models\CompetencySnapshot;
use App\Models\DnaSnapshot;
use App\Models\GrowthSnapshot;
use App\Models\Project;
use App\Models\SkillGapSnapshot;
use App\Services\Growth\GrowthRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Reads a project's historical DNA (docs/architecture/historical-dna-v1.md)
 * from the existing immutable snapshots. Nothing is calculated, stored or
 * changed here.
 *
 * A point is a DNA snapshot of the project, owned by the project's owner,
 * whose analysis run SUCCEEDED and completed. Points are ordered newest
 * first by analysis run completion, then run ID, then DNA snapshot
 * creation and ID (the order Phase 18 uses for baselines). Each page loads
 * its layers with a fixed number of queries, whatever its size.
 */
final readonly class HistoryReader
{
    public function __construct(private GrowthRules $rules) {}

    /**
     * @return LengthAwarePaginator<int, HistoryPoint>
     */
    public function page(Project $project, int $page, int $perPage): LengthAwarePaginator
    {
        $paginator = self::ordered($this->eligible($project))->paginate($perPage, page: $page);
        /** @var Collection<int, DnaSnapshot> $snapshots */
        $snapshots = new Collection($paginator->items());

        return new LengthAwarePaginator(
            $this->points($snapshots),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
        );
    }

    /**
     * A keyset page of the same history, in the same order (Phase 26): no
     * COUNT and no OFFSET, one bounded walk of the project's history index.
     *
     * @return CursorPage<HistoryPoint>
     */
    public function cursorPage(Project $project, ?string $cursor, int $perPage, KeysetPaginator $keyset): CursorPage
    {
        $page = $keyset->paginate(
            $this->eligible($project)->addSelect('analysis_runs.completed_at as history_completed_at'),
            ['analysis_runs.completed_at', 'analysis_runs.id', 'dna_snapshots.created_at', 'dna_snapshots.id'],
            fn (DnaSnapshot $dna): array => [
                (string) $dna->getRawOriginal('history_completed_at'), $dna->analysis_run_id,
                (string) $dna->getRawOriginal('created_at'), $dna->id,
            ],
            'history:'.$project->id, $cursor, $perPage, indexPrefix: 2,
        );

        return new CursorPage($this->points(new Collection($page->items)), $page->perPage, $page->nextCursor, $page->previousCursor);
    }

    public function find(Project $project, string $dnaSnapshotId): ?HistoryPoint
    {
        return $this->findMany($project, [$dnaSnapshotId])[0] ?? null;
    }

    /**
     * The requested points that are eligible history of this project, oldest
     * first. IDs of other projects or users, or of ineligible snapshots, are
     * simply absent.
     *
     * @param  list<string>  $dnaSnapshotIds
     * @return list<HistoryPoint>
     */
    public function findMany(Project $project, array $dnaSnapshotIds): array
    {
        $snapshots = self::ordered($this->eligible($project)->whereIn('dna_snapshots.id', array_map('strtolower', $dnaSnapshotIds)))->get();

        return array_reverse($this->points($snapshots));
    }

    /**
     * The points immediately before and after this one, as references.
     *
     * @return array{previous: array{dna_snapshot_id: string, analyzed_at: string}|null, next: array{dna_snapshot_id: string, analyzed_at: string}|null}
     */
    public function neighbours(Project $project, HistoryPoint $point): array
    {
        $key = [
            $point->run->getRawOriginal('completed_at'), $point->run->id,
            $point->dna->getRawOriginal('created_at'), $point->dna->id,
        ];
        $tuple = '(analysis_runs.completed_at, analysis_runs.id, dna_snapshots.created_at, dna_snapshots.id)';
        // The run prefix (completed_at, id) is a range on the project's history
        // index, so each neighbour is found by a short index walk from the
        // point instead of a scan of the whole history (Phase 26); the full
        // tuple then decides between DNA snapshots of the same run.
        $prefix = '(analysis_runs.completed_at, analysis_runs.id)';
        $previous = self::ordered($this->eligible($project)
            ->whereRaw("{$prefix} <= (?, ?)", array_slice($key, 0, 2))
            ->whereRaw("{$tuple} < (?, ?, ?, ?)", $key))
            ->addSelect('analysis_runs.completed_at as analyzed_at')->first();
        $next = self::ordered($this->eligible($project)
            ->whereRaw("{$prefix} >= (?, ?)", array_slice($key, 0, 2))
            ->whereRaw("{$tuple} > (?, ?, ?, ?)", $key), 'asc')
            ->addSelect('analysis_runs.completed_at as analyzed_at')->first();

        return ['previous' => self::reference($previous), 'next' => self::reference($next)];
    }

    /**
     * @return array{dna_snapshot_id: string, analyzed_at: string}|null
     */
    private static function reference(?DnaSnapshot $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'dna_snapshot_id' => $snapshot->id,
            'analyzed_at' => Carbon::parse((string) $snapshot->getAttribute('analyzed_at'))->toIso8601ZuluString(),
        ];
    }

    /**
     * @return Builder<DnaSnapshot>
     */
    private function eligible(Project $project): Builder
    {
        return DnaSnapshot::query()
            ->select('dna_snapshots.*')
            ->join('analysis_runs', function ($join): void {
                $join->on('analysis_runs.id', '=', 'dna_snapshots.analysis_run_id')
                    ->on('analysis_runs.project_id', '=', 'dna_snapshots.project_id');
            })
            ->where('dna_snapshots.project_id', $project->id)
            ->where('dna_snapshots.user_id', $project->user_id)
            ->where('analysis_runs.status', AnalysisRunStatus::Succeeded->value)
            ->whereNotNull('analysis_runs.completed_at');
    }

    /**
     * @param  Builder<DnaSnapshot>  $query
     * @return Builder<DnaSnapshot>
     */
    private static function ordered(Builder $query, string $direction = 'desc'): Builder
    {
        return $query
            ->orderBy('analysis_runs.completed_at', $direction)->orderBy('analysis_runs.id', $direction)
            ->orderBy('dna_snapshots.created_at', $direction)->orderBy('dna_snapshots.id', $direction);
    }

    /**
     * The snapshots with their layers, in the given order. Seven queries for
     * any number of snapshots: runs, sources, competencies, skill gaps, their
     * results, growth snapshots and their observations.
     *
     * @param  Collection<int, DnaSnapshot>  $snapshots
     * @return list<HistoryPoint>
     */
    private function points(Collection $snapshots): array
    {
        if ($snapshots->isEmpty()) {
            return [];
        }
        $snapshots->load(['analysisRun', 'sourceSnapshot']);
        $projectId = (string) $snapshots->first()?->project_id;

        // The newest competency snapshot of each DNA snapshot.
        $competencies = [];
        $rows = CompetencySnapshot::query()
            ->where('project_id', $projectId)
            ->whereIn('dna_snapshot_id', $snapshots->modelKeys())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();
        foreach ($rows as $row) {
            $competencies[$row->dna_snapshot_id] ??= $row;
        }

        // The newest skill gap snapshot of each of those competency snapshots.
        $gaps = [];
        $competencyIds = array_map(fn (CompetencySnapshot $c): string => $c->id, array_values($competencies));
        if ($competencyIds !== []) {
            $rows = SkillGapSnapshot::query()
                ->where('project_id', $projectId)
                ->whereIn('competency_snapshot_id', $competencyIds)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->get();
            foreach ($rows as $row) {
                $gaps[$row->competency_snapshot_id] ??= $row;
            }
            (new Collection(array_values($gaps)))->load('results');
        }

        // Each assessment's stored growth (Phase 18) under the current rules.
        $growth = [];
        $gapIds = array_map(fn (SkillGapSnapshot $g): string => $g->id, array_values($gaps));
        if ($gapIds !== []) {
            $rows = GrowthSnapshot::query()
                ->where('project_id', $projectId)
                ->whereIn('skill_gap_snapshot_id', $gapIds)
                ->where('rules_version', $this->rules->version)
                ->with('observations')
                ->get();
            foreach ($rows as $row) {
                $growth[$row->skill_gap_snapshot_id] = $row;
            }
        }

        $points = [];
        foreach ($snapshots as $dna) {
            $competency = $competencies[$dna->id] ?? null;
            $skillGaps = $competency === null ? null : ($gaps[$competency->id] ?? null);
            $points[] = new HistoryPoint(
                dna: $dna,
                run: $dna->analysisRun,
                source: $dna->sourceSnapshot,
                competency: $competency,
                skillGaps: $skillGaps,
                growth: $skillGaps === null ? null : ($growth[$skillGaps->id] ?? null),
            );
        }

        return $points;
    }
}
