<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Enums\Organizations\OrganizationAuditAction;
use App\Http\Pagination\CursorCodec;
use App\Http\Pagination\CursorDirection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Organizations\OrganizationAudit;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\AssessmentFixtures;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

/**
 * Keyset pagination (Phase 26, docs/api/README.md#pagination): complete,
 * ordered, stable under concurrent inserts, and cursors that are opaque,
 * bound to their list and tamper-evident.
 */
final class CursorPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = OrganizationFixtures::create($this->owner);
    }

    private function event(int $secondsAgo): void
    {
        Carbon::setTestNow(Carbon::now()->subSeconds($secondsAgo));
        app(OrganizationAudit::class)->record($this->organization, $this->owner, OrganizationAuditAction::OrganizationUpdated, null, ['name' => "rename {$secondsAgo}"]);
        Carbon::setTestNow();
    }

    /** @return list<string> */
    private function expectedAudit(): array
    {
        return DB::table('organization_audit_events')->where('organization_id', $this->organization->id)
            ->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
    }

    private function audit(string $query = ''): TestResponse
    {
        return $this->asUser($this->owner)->getJson("/api/v1/organizations/{$this->organization->id}/audit-events{$query}");
    }

    public function test_walking_the_audit_log_forward_and_back_visits_every_event_once_in_order(): void
    {
        // Several events in the same second: the id breaks the tie.
        foreach ([50, 40, 40, 40, 30, 20, 20, 10, 5] as $ago) {
            $this->event($ago);
        }
        $expected = $this->expectedAudit();

        $seen = [];
        $pages = [];
        $cursor = '';
        do {
            $page = $this->audit('?per_page=2&cursor='.rawurlencode($cursor))->assertOk();
            $this->assertArrayNotHasKey('total', $page->json('meta'));
            $pages[] = $page;
            $seen = [...$seen, ...array_column($page->json('data'), 'id')];
            $cursor = $page->json('meta.next_cursor');
        } while ($cursor !== null);
        $this->assertSame($expected, $seen);
        $this->assertNull($pages[0]->json('meta.prev_cursor'), 'the first page has nothing newer');

        // Back from the last page with prev_cursor gives the same pages, in order.
        $back = [];
        $cursor = end($pages)->json('meta.prev_cursor');
        while ($cursor !== null) {
            $page = $this->audit('?per_page=2&cursor='.rawurlencode($cursor))->assertOk();
            $back = [...array_column($page->json('data'), 'id'), ...$back];
            $cursor = $page->json('meta.prev_cursor');
        }
        $this->assertSame(array_slice($expected, 0, count($expected) - count(end($pages)->json('data'))), $back);
    }

    public function test_events_recorded_while_paging_never_shift_or_repeat_older_pages(): void
    {
        foreach ([60, 50, 40, 30] as $ago) {
            $this->event($ago);
        }
        $before = $this->expectedAudit();
        $first = $this->audit('?per_page=2&cursor=')->assertOk();
        $this->event(0);
        $this->event(0);

        $second = $this->audit('?per_page=2&cursor='.rawurlencode($first->json('meta.next_cursor')))->assertOk();
        $this->assertSame(array_slice($before, 2, 2), array_column($second->json('data'), 'id'));
    }

    public function test_the_audit_log_is_paged_by_cursor_only_and_bounded(): void
    {
        $this->audit('?page=2')->assertUnprocessable();
        $this->audit('?per_page=101')->assertUnprocessable();
        $this->audit('?cursor='.str_repeat('a', 513))->assertUnprocessable();
    }

    public function test_altered_foreign_or_unsigned_cursors_are_refused(): void
    {
        foreach ([60, 50, 40] as $ago) {
            $this->event($ago);
        }
        $cursor = $this->audit('?per_page=1&cursor=')->json('meta.next_cursor');
        [$payload, $tag] = explode('.', $cursor);

        // An opaque value: base64url JSON of the key, the direction and the list, plus a tag. No SQL.
        $decoded = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame(['l', 'v', 'd'], array_keys($decoded));
        $this->assertSame("audit:{$this->organization->id}", $decoded['l']);

        $altered = rtrim(strtr(base64_encode((string) json_encode([...$decoded, 'v' => ['2000-01-01 00:00:00', $decoded['v'][1]]])), '+/', '-_'), '=');
        foreach (["{$altered}.{$tag}", "{$payload}.".strrev($tag), $payload, 'not-a-cursor', "{$payload}.{$tag}.x"] as $bad) {
            $this->audit('?cursor='.rawurlencode($bad))->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }

        // A valid cursor of another organization's log is refused here, not followed.
        $other = OrganizationFixtures::create($this->owner, 'Other');
        $foreign = app(CursorCodec::class)->encode("audit:{$other->id}", $decoded['v'], CursorDirection::Next);
        $this->audit('?cursor='.rawurlencode($foreign))->assertUnprocessable();
        // Or of another kind of list.
        $wrongList = app(CursorCodec::class)->encode("analyses:{$this->organization->id}", $decoded['v'], CursorDirection::Next);
        $this->audit('?cursor='.rawurlencode($wrongList))->assertUnprocessable();
        // A cursor signed with another key (an old APP_KEY) is refused.
        $rotated = new CursorCodec(new Repository(['app' => ['key' => 'base64:'.base64_encode(random_bytes(32))]]));
        $this->expectException(ValidationException::class);
        $rotated->decode("audit:{$this->organization->id}", $cursor, 2);
    }

    public function test_a_signed_cursor_must_carry_exactly_the_lists_key(): void
    {
        // Defence in depth: even a correctly signed cursor with too few or
        // too many key values for its list is refused before any query.
        $codec = app(CursorCodec::class);
        $list = "audit:{$this->organization->id}";
        foreach ([['2026-01-01 00:00:00'], ['2026-01-01 00:00:00', 'a', 'b']] as $values) {
            try {
                $codec->decode($list, $codec->encode($list, $values, CursorDirection::Next), 2);
                $this->fail('A cursor with '.count($values).' values was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([['2026-01-01 00:00:00', 'a'], CursorDirection::Previous],
            $codec->decode($list, $codec->encode($list, ['2026-01-01 00:00:00', 'a'], CursorDirection::Previous), 2));
    }

    public function test_cursor_pages_keep_the_lists_authorization(): void
    {
        $member = OrganizationFixtures::member($this->organization)->user;
        $this->asUser($member)->getJson("/api/v1/organizations/{$this->organization->id}/audit-events?cursor=")->assertForbidden();
        $this->asUser(User::factory()->create())->getJson("/api/v1/organizations/{$this->organization->id}/audit-events?cursor=")->assertNotFound();

        $project = Project::factory()->for($this->owner)->create();
        $this->asUser(User::factory()->create())->getJson("/api/v1/projects/{$project->id}/history?cursor=")->assertNotFound();
    }

    public function test_project_histories_in_cursor_mode_list_exactly_what_page_mode_lists(): void
    {
        $project = Project::factory()->for($this->owner)->create();
        for ($i = 0; $i < 5; $i++) {
            AssessmentFixtures::skillGaps($project);
        }
        $base = "/api/v1/projects/{$project->id}";
        foreach (['/history', '/analyses', '/source-snapshots', '/growth/timeline'] as $list) {
            $paged = array_column($this->asUser($this->owner)->getJson("{$base}{$list}?per_page=100")->assertOk()->json('data'), 'id');
            $walked = [];
            $cursor = '';
            do {
                $page = $this->asUser($this->owner)->getJson("{$base}{$list}?per_page=2&cursor=".rawurlencode($cursor))->assertOk();
                $walked = [...$walked, ...array_column($page->json('data'), 'id')];
                $cursor = $page->json('meta.next_cursor');
            } while ($cursor !== null);
            $this->assertSame($paged, $walked, "{$list}: cursor and page modes list the same items in the same order");
            // Page and cursor together are refused, and a list's cursor is not another list's.
            $this->asUser($this->owner)->getJson("{$base}{$list}?page=1&cursor=x")->assertUnprocessable();
        }
        $historyCursor = $this->asUser($this->owner)->getJson("{$base}/history?per_page=1&cursor=")->json('meta.next_cursor');
        $this->asUser($this->owner)->getJson("{$base}/analyses?cursor=".rawurlencode($historyCursor))->assertUnprocessable();
    }
}
