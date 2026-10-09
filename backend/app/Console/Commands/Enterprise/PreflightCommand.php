<?php

declare(strict_types=1);

namespace App\Console\Commands\Enterprise;

use App\Services\Enterprise\EnterpriseEdition;
use App\Services\Enterprise\LicenseStatus;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * php artisan codedna:preflight (Phase 27, docs/enterprise/self-hosted-installation.md):
 * checks an installation before it is started or upgraded. The configuration
 * has already been validated when this command runs (the application refuses
 * to boot otherwise); it then checks that PostgreSQL, Redis and object
 * storage answer with the configured credentials, reports pending
 * migrations, and shows the edition and registration mode.
 *
 * Read-only: nothing is written or migrated. Failures name the dependency,
 * never a host, credential or exception message (those go to the log as the
 * exception class only).
 */
final class PreflightCommand extends Command
{
    protected $signature = 'codedna:preflight';

    protected $description = 'Check configuration, database, Redis, object storage, migrations and license before starting';

    public function handle(Repository $config, Migrator $migrator, EnterpriseEdition $edition): int
    {
        $rows = [['configuration', 'ok', 'validated at boot']];
        $failed = false;
        $probe = function (string $name, callable $check, string $detail) use (&$rows, &$failed): bool {
            try {
                $check();
                $rows[] = [$name, 'ok', $detail];

                return true;
            } catch (Throwable $e) {
                Log::warning('preflight.failed', ['check' => $name, 'exception' => $e::class]);
                $rows[] = [$name, 'FAIL', 'see docs/enterprise/troubleshooting.md'];
                $failed = true;

                return false;
            }
        };

        $database = $probe('database', fn () => DB::connection()->select('select 1'), 'connected');
        if ($database) {
            $pending = 'unknown';
            $probe('migrations', function () use ($migrator, &$pending): void {
                $migrator->setConnection(null);
                if (! $migrator->repositoryExists()) {
                    $pending = 'not installed: run the migration job';

                    return;
                }
                $ran = $migrator->getRepository()->getRan();
                $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
                $count = count(array_diff($files, $ran));
                $pending = $count === 0 ? 'up to date' : "{$count} pending: run the migration job";
            }, '');
            $rows[count($rows) - 1][2] = $pending;
        }
        $probe('redis', fn () => Redis::connection()->ping(), 'connected');
        $probe('redis (cache)', fn () => Redis::connection('cache')->ping(), 'connected');
        // A HEAD request for an object that does not exist: proves the
        // endpoint, bucket and credentials without writing anything.
        $probe('object storage', fn () => Storage::disk((string) $config->get('codedna.sources.disk'))->exists('codedna-preflight-probe'), 'bucket reachable');

        $verification = $edition->verification();
        $licensed = $verification->status === LicenseStatus::Valid;
        $rows[] = ['license', $verification->status->value, $licensed ? 'Enterprise edition' : 'Community edition'];
        $mode = (string) $config->get('codedna.registration.mode');
        $rows[] = ['registration', $mode, $mode === 'restricted' ? implode(', ', (array) $config->get('codedna.registration.allowed_email_domains')) : ''];

        $this->table(['Check', 'Status', 'Detail'], $rows);
        if ($failed) {
            $this->error('Preflight failed.');

            return self::FAILURE;
        }
        if (! in_array($verification->status, [LicenseStatus::Valid, LicenseStatus::Absent], true)) {
            $this->warn('A license is configured but grants nothing (see codedna:license).');
        }
        $this->info('Preflight passed.');

        return self::SUCCESS;
    }
}
