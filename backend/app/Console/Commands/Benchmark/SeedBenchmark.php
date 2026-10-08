<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use App\Actions\Analysis\StartAnalysis;
use App\Actions\Organizations\CreateOrganization;
use App\Actions\Projects\CreateProject;
use App\Actions\Roadmap\GenerateRoadmap;
use App\Actions\Snapshots\StoreUploadedSource;
use App\Enums\AnalysisResultType;
use App\Models\AnalysisRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Benchmark data generator (Phase 26, docs/performance/benchmarking.md).
 *
 * Runs only against a dedicated database whose name ends in "_benchmark",
 * outside production (BenchmarkGuard). Two steps:
 *
 * 1. Template: one synthetic project goes through the REAL pipeline (upload
 *    to object storage, analyzer, DNA, competencies, skill gaps, growth,
 *    roadmap), so every derived row has a genuine shape and size.
 * 2. Scale: set-based SQL (generate_series) clones that template into
 *    organizations, users, memberships, projects and their histories, with
 *    deterministic identifiers ("0" + table code + sequence: valid ULIDs)
 *    and deterministic timestamps. No row is random; the same scale always
 *    yields the same data.
 *
 * Cloned source snapshots point at storage keys with no object behind them:
 * they are never analyzed. Remove everything with `make benchmark-clean`.
 */
final class SeedBenchmark extends Command
{
    /** Password of every benchmark user: a constant for a throwaway local database, never a credential. */
    public const PASSWORD = 'benchmark-only-not-a-secret';

    public const TEMPLATE_EMAIL = 'template@benchmark.invalid';

    protected $signature = 'benchmark:seed
        {--scale=small : small | medium | large (docs/performance/benchmarking.md#scales)}
        {--sources= : Directory with template-v1.zip and template-v2.zip (scripts/benchmark/make_sources.py)}';

    protected $description = 'Generate deterministic benchmark data in the dedicated benchmark database';

    public function handle(BenchmarkGuard $guard, CreateProject $createProject, StoreUploadedSource $store, StartAnalysis $start, GenerateRoadmap $roadmap, CreateOrganization $organization, BenchmarkScaler $scaler): int
    {
        $guard->assertBenchmarkDatabase();
        $scale = BenchmarkScale::named((string) $this->option('scale'));
        $sources = rtrim((string) $this->option('sources'), '/');
        foreach (['template-v1.zip', 'template-v2.zip'] as $file) {
            if (! is_file("{$sources}/{$file}")) {
                $this->error("Missing {$sources}/{$file}: run scripts/benchmark/make_sources.py first.");

                return self::INVALID;
            }
        }

        $started = microtime(true);
        $template = User::query()->where('email', self::TEMPLATE_EMAIL)->first()
            ?? $this->template($createProject, $store, $start, $roadmap, $organization, $sources);
        $this->info(sprintf('Template ready (%.1fs).', microtime(true) - $started));

        $counts = $scaler->scale($template, $scale, fn (string $line) => $this->line($line));
        $this->table(['table', 'rows'], array_map(null, array_keys($counts), array_values($counts)));
        $this->info(sprintf('Benchmark data "%s" ready in %.1fs.', $scale->name, microtime(true) - $started));

        return self::SUCCESS;
    }

    private function template(CreateProject $createProject, StoreUploadedSource $store, StartAnalysis $start, GenerateRoadmap $roadmap, CreateOrganization $organization, string $sources): User
    {
        // The template's analyses run inline here (the real job, the real
        // analyzer), not on a worker.
        config(['codedna.analysis.queue_connection' => 'sync']);

        $user = new User;
        $user->forceFill([
            'name' => 'Benchmark Template',
            'email' => self::TEMPLATE_EMAIL,
            'password' => Hash::make(self::PASSWORD),
        ])->save();

        $project = $createProject->handle($user, [
            'name' => 'Benchmark template',
            'slug' => 'benchmark-template',
            'source_type' => 'UPLOAD',
            'language' => 'python',
        ]);

        foreach (['template-v1.zip', 'template-v2.zip'] as $file) {
            // StoreUploadedSource consumes a temporary upload file: hand it a copy.
            $copy = tempnam(sys_get_temp_dir(), 'bench');
            copy("{$sources}/{$file}", $copy);
            $stored = $store->handle($project, $user, $copy);
            @unlink($copy);
            $started = $start->handle($project, $user, $stored->snapshot->id, AnalysisResultType::StaticAnalysis);
            $run = AnalysisRun::query()->findOrFail($started->run->id);
            if ($run->status->value !== 'SUCCEEDED') {
                throw new RuntimeException("Template analysis {$run->id} ended {$run->status->value}: is the analyzer running?");
            }
        }

        $roadmap->handle(Project::query()->findOrFail($project->id), $user);
        // The organization's billing account and first audit event are cloned too.
        $organization->handle($user, 'Benchmark template organization');

        foreach (['dna_snapshots', 'competency_snapshots', 'skill_gap_snapshots', 'growth_snapshots', 'roadmap_snapshots'] as $table) {
            if (DB::table($table)->where('project_id', $project->id)->doesntExist()) {
                throw new RuntimeException("The template produced no {$table} rows.");
            }
        }

        return $user;
    }
}
