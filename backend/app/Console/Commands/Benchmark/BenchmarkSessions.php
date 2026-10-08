<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use App\Actions\Auth\StartUserSession;
use App\Actions\Projects\CreateProject;
use App\Actions\Snapshots\StoreUploadedSource;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Hash;

/**
 * Sessions for the HTTP load test (Phase 26, docs/performance/load-testing.md).
 *
 * Signs benchmark users in through the application's own session start
 * (StartUserSession, the code a successful login runs) and writes, per user,
 * the cookies a browser would hold and the X-XSRF-TOKEN value it would send:
 * the session cookie and XSRF token encrypted exactly as EncryptCookies does.
 * Every load-test request then passes the real session, Sanctum and CSRF
 * middleware. Logging hundreds of users in over HTTP would instead hit the
 * login rate limits (by design), which are not what a load test measures.
 *
 * With --writers it also prepares users for the write scenarios: each gets a
 * project with --snapshots uploaded source snapshots (real uploads of the
 * given archive) that have no analysis yet.
 *
 * Benchmark database only; the output holds live session cookies for that
 * throwaway database and is written to a git-ignored path.
 */
final class BenchmarkSessions extends Command
{
    protected $signature = 'benchmark:sessions
        {--users=400 : Reader sessions (seeded users)}
        {--writers=40 : Writer sessions (new users with fresh projects)}
        {--snapshots=4 : Unanalyzed source snapshots per writer}
        {--archive= : Archive to upload for writers}
        {--out=storage/app/benchmark/sessions.json : Where to write the sessions}';

    protected $description = 'Create authenticated sessions for the HTTP load test';

    public function handle(BenchmarkGuard $guard, SessionManager $sessions, Encrypter $encrypter, CreateProject $createProject, StoreUploadedSource $store): int
    {
        $guard->assertBenchmarkDatabase();
        $endpoints = new BenchmarkEndpoints;
        $out = ['readers' => [], 'writers' => []];

        $readers = (int) $this->option('users');
        $total = (int) User::query()->where('id', 'like', '010%')->count();
        for ($i = 1; $i <= min($readers, $total); $i++) {
            $user = User::query()->findOrFail(BenchmarkEndpoints::id(10, $i));
            $out['readers'][] = ['user' => $i] + $this->session($user, $sessions, $encrypter) + $endpoints->subjectsFor($i);
        }

        $archive = (string) $this->option('archive');
        $batch = 'w'.substr(md5((string) microtime(true)), 0, 6);
        for ($i = 1; $i <= (int) $this->option('writers'); $i++) {
            $user = new User;
            $user->forceFill(['name' => "Load writer {$i}", 'email' => "{$batch}-{$i}@benchmark.invalid", 'password' => Hash::make(SeedBenchmark::PASSWORD)])->save();
            $project = $createProject->handle($user, ['name' => "Load {$batch} {$i}", 'slug' => "load-{$batch}-{$i}", 'source_type' => 'UPLOAD']);
            $snapshots = [];
            for ($s = 0; $s < (int) $this->option('snapshots'); $s++) {
                $copy = (string) tempnam(sys_get_temp_dir(), 'bench');
                copy($archive, $copy);
                $snapshots[] = $store->handle($project, $user, $copy)->snapshot->id;
                @unlink($copy);
            }
            $out['writers'][] = ['user' => $user->id, 'project' => $project->id, 'snapshots' => $snapshots] + $this->session($user, $sessions, $encrypter);
        }

        $path = base_path((string) $this->option('out'));
        file_put_contents($path, json_encode($out, JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        $this->info(sprintf('%d reader and %d writer sessions written to %s.', count($out['readers']), count($out['writers']), $this->option('out')));

        return self::SUCCESS;
    }

    /** @return array{cookie: string, xsrf: string} */
    private function session(User $user, SessionManager $sessions, Encrypter $encrypter): array
    {
        $cookie = (string) config('session.cookie');
        // Built like SessionManager builds stores: the same handler and serialization.
        $store = new Store($cookie, $sessions->driver()->getHandler(), null, (string) config('session.serialization', 'php'));
        $store->start();
        // The guard writes into the session bound when it is created.
        app()->instance('session.store', $store);
        auth()->forgetGuards();
        app(StartUserSession::class)->handle($user, $store);
        $store->save();

        $encrypt = fn (string $name, string $value): string => $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()).$value, false);
        $xsrf = $encrypt('XSRF-TOKEN', $store->token());

        return [
            'cookie' => $cookie.'='.rawurlencode($encrypt($cookie, $store->getId())).'; XSRF-TOKEN='.rawurlencode($xsrf),
            'xsrf' => $xsrf,
        ];
    }
}
