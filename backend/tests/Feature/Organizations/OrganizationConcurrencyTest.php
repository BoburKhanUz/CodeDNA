<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\AcceptInvitation;
use App\Actions\Organizations\ChangeMembership;
use App\Actions\Organizations\InviteMember;
use App\Actions\Projects\CreateProject;
use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationRole;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\BillingFixtures;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Phase 24: seats, acceptances and team project slots under real
 * concurrency (one process and connection each, against real PostgreSQL),
 * and the owner invariant at a real commit. Rows are committed, so this test
 * does not use RefreshDatabase's transaction; it deletes what it created.
 */
final class OrganizationConcurrencyTest extends TestCase
{
    private User $owner;

    private Organization $organization;

    /** @var list<string> */
    private array $users = [];

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
        $this->owner = $this->user();
        $this->organization = OrganizationFixtures::create($this->owner);
    }

    protected function tearDown(): void
    {
        OrganizationFixtures::forget(DB::table('organizations')->whereIn('owner_user_id', $this->users)->pluck('id')->all());
        BillingFixtures::forget($this->users);
        DB::table('users')->whereIn('id', $this->users)->delete();
        parent::tearDown();
    }

    private function user(?string $email = null): User
    {
        $user = User::factory()->create($email === null ? [] : ['email' => $email]);
        $this->users[] = $user->id;

        return $user;
    }

    /**
     * @param  list<Closure(): string>  $racers
     * @return list<string> sorted results
     */
    private function race(array $racers): array
    {
        $dir = sys_get_temp_dir().'/codedna-org-race-'.Str::random(8);
        mkdir($dir);
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
                    Queue::fake();
                    $this->app->forgetScopedInstances();
                    time_sleep_until($startAt);
                    $result = $racer();
                } catch (ApiException $e) {
                    $result = 'refused '.$e->errorCode->value;
                } catch (Throwable $e) {
                    $result = 'error '.$e::class.' '.$e->getMessage();
                }
                file_put_contents("{$dir}/{$i}", $result);
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
            $results[] = (string) @file_get_contents("{$dir}/{$i}");
            @unlink("{$dir}/{$i}");
        }
        @rmdir($dir);
        sort($results);

        return $results;
    }

    private function activeMembers(): int
    {
        return OrganizationMembership::query()->where('organization_id', $this->organization->id)->where('status', 'ACTIVE')->count();
    }

    public function test_concurrent_acceptances_cannot_exceed_the_seats(): void
    {
        foreach (range(1, 3) as $i) {
            OrganizationFixtures::member($this->organization, $this->user());
        }
        $racers = [];
        foreach (range(1, 3) as $i) {
            $invitee = $this->user("race{$i}-".Str::lower(Str::random(6)).'@example.com');
            [, $token] = app(InviteMember::class)->handle($this->organization, $this->owner, $invitee->email, OrganizationRole::Member);
            $racers[] = fn (): string => app(AcceptInvitation::class)->handle(User::query()->findOrFail($invitee->id), $token)->status->value;
        }

        $this->assertSame(['ACTIVE', 'refused SEAT_LIMIT_REACHED', 'refused SEAT_LIMIT_REACHED'], $this->race($racers));
        $this->assertSame(5, $this->activeMembers());
    }

    public function test_one_invitation_accepted_concurrently_joins_once(): void
    {
        $invitee = $this->user('once-'.Str::lower(Str::random(6)).'@example.com');
        [, $token] = app(InviteMember::class)->handle($this->organization, $this->owner, $invitee->email, OrganizationRole::Member);

        $results = $this->race(array_fill(0, 3, fn (): string => app(AcceptInvitation::class)->handle(User::query()->findOrFail($invitee->id), $token)->status->value));

        $this->assertSame(['ACTIVE', 'refused INVITATION_ALREADY_ACCEPTED', 'refused INVITATION_ALREADY_ACCEPTED'], $results);
        $this->assertSame(1, OrganizationMembership::query()->where('user_id', $invitee->id)->count());
        $this->assertSame(1, DB::table('organization_audit_events')->where('organization_id', $this->organization->id)->where('action', 'MEMBER_JOINED')->count());
    }

    public function test_concurrent_reactivations_cannot_exceed_the_seats(): void
    {
        foreach (range(1, 3) as $i) {
            OrganizationFixtures::member($this->organization, $this->user());
        }
        $suspended = [OrganizationFixtures::member($this->organization, $this->user(), status: MembershipStatus::Suspended),
            OrganizationFixtures::member($this->organization, $this->user(), status: MembershipStatus::Suspended)];
        $racers = array_map(fn (OrganizationMembership $m): Closure => fn (): string => app(ChangeMembership::class)->handle(
            Organization::query()->findOrFail($this->organization->id), User::query()->findOrFail($this->owner->id),
            OrganizationMembership::query()->findOrFail($m->id), null, MembershipStatus::Active)->status->value, $suspended);

        $this->assertSame(['ACTIVE', 'refused SEAT_LIMIT_REACHED'], $this->race($racers));
        $this->assertSame(5, $this->activeMembers());
    }

    public function test_concurrent_team_project_creations_cannot_exceed_the_organizations_slots(): void
    {
        OrganizationFixtures::project($this->organization, $this->owner);
        OrganizationFixtures::project($this->organization, $this->owner);
        $racers = array_map(fn (int $i): Closure => fn (): string => app(CreateProject::class)->handle(User::query()->findOrFail($this->owner->id),
            ['name' => "P{$i}", 'slug' => "race-{$i}", 'source_type' => 'UPLOAD'], Organization::query()->findOrFail($this->organization->id))->slug ? 'created' : '', range(1, 4));

        $this->assertSame(['created', 'refused QUOTA_EXCEEDED', 'refused QUOTA_EXCEEDED', 'refused QUOTA_EXCEEDED'], $this->race($racers));
        $this->assertSame(3, Project::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, Project::query()->where('user_id', $this->owner->id)->whereNull('organization_id')->count());
    }

    public function test_the_owner_invariant_is_enforced_when_a_transaction_commits(): void
    {
        $membership = OrganizationFixtures::membershipOf($this->organization, $this->owner);

        // Deferred checks fail at COMMIT, which PDO reports as a PDOException (QueryException's parent).
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('organization_memberships')->where('id', $membership->id)->update(['status' => 'REMOVED'])),
            PDOException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('organization_billing_accounts')->where('organization_id', $this->organization->id)->delete()),
            PDOException::class);
        $this->assertSame('ACTIVE', $membership->refresh()->status->value);
        $this->assertSame(1, DB::table('organization_billing_accounts')->where('organization_id', $this->organization->id)->count());
    }
}
