<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Actions\Projects\CreateProject;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

/**
 * Phase 22: the unique-constraint race branches of registration and project
 * creation, with real concurrency (one process and one connection each,
 * against real PostgreSQL). Validation passes for every racer; the database
 * decides, and the losers must get the same 422 as a sequential duplicate,
 * never a 500.
 *
 * Rows are committed (other processes must see them), so this test does not
 * use RefreshDatabase's transaction; it deletes what it created.
 */
final class AccountConcurrencyTest extends TestCase
{
    private const RACERS = 5;

    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->tag = strtolower(Str::random(10));
    }

    protected function tearDown(): void
    {
        $users = DB::table('users')->where('email', 'like', "race-{$this->tag}%")->pluck('id');
        DB::table('projects')->whereIn('user_id', $users)->delete();
        DB::table('developer_profiles')->whereIn('user_id', $users)->delete();
        DB::table('users')->whereIn('id', $users)->delete();
        parent::tearDown();
    }

    /**
     * Runs each closure in its own process, all starting at the same moment.
     *
     * @param  list<Closure(): string>  $racers
     * @return list<string> what each one reported, sorted
     */
    private function race(array $racers): array
    {
        $resultDir = sys_get_temp_dir().'/codedna-race-'.Str::random(8);
        mkdir($resultDir);
        DB::disconnect();
        $startAt = microtime(true) + 0.5;
        $children = [];
        foreach ($racers as $i => $racer) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                try {
                    DB::purge();
                    time_sleep_until($startAt);
                    $result = $racer();
                } catch (ValidationException $e) {
                    $result = 'invalid '.implode(',', array_keys($e->errors()));
                } catch (Throwable $e) {
                    $result = 'error '.$e::class;
                }
                file_put_contents("{$resultDir}/{$i}", $result);
                posix_kill(getmypid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = [];
        foreach (array_keys($racers) as $i) {
            $results[] = (string) @file_get_contents("{$resultDir}/{$i}");
            @unlink("{$resultDir}/{$i}");
        }
        @rmdir($resultDir);
        sort($results);

        return $results;
    }

    public function test_concurrent_registrations_with_one_email_create_one_account(): void
    {
        $email = "race-{$this->tag}@example.com";
        $racer = function () use ($email): string {
            $this->forgetSessionState();

            return (string) $this->fromBrowser()->postJson('/api/v1/auth/register', [
                'name' => 'Racer', 'email' => $email, 'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
            ])->getStatusCode();
        };

        $results = $this->race(array_fill(0, self::RACERS, $racer));

        $this->assertSame(['201', ...array_fill(0, self::RACERS - 1, '422')], $results);
        $this->assertSame(1, DB::table('users')->where('email', $email)->count());
        $this->assertSame(1, DB::table('developer_profiles')->whereIn('user_id', DB::table('users')->where('email', $email)->select('id'))->count());
    }

    public function test_concurrent_project_creations_with_one_slug_create_one_project(): void
    {
        $owner = User::factory()->create(['email' => "race-{$this->tag}-owner@example.com"]);
        $racer = fn (): string => app(CreateProject::class)->handle(User::query()->findOrFail($owner->id), [
            'name' => 'Racer', 'slug' => 'racer', 'source_type' => 'UPLOAD',
        ])->slug;

        $results = $this->race(array_fill(0, self::RACERS, $racer));

        $this->assertSame([...array_fill(0, self::RACERS - 1, 'invalid slug'), 'racer'], $results);
        $this->assertSame(1, DB::table('projects')->where('user_id', $owner->id)->count());
    }
}
