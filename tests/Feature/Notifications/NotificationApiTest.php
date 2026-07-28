<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\Notifications\NotificationActionRoute;
use App\Services\Notifications\NotificationIntent;
use App\Services\Notifications\NotificationWriter;
use Illuminate\Support\Carbon;
use Tests\Feature\Peminjaman\RoomBookingApiTestCase;

/**
 * Recipient-owned notification API: ordering, filters, unread counts, read vs
 * resolved, mark-all, and strict IDOR isolation across accounts.
 */
class NotificationApiTest extends RoomBookingApiTestCase
{
    private NotificationWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = app(NotificationWriter::class);
    }

    public function test_list_is_owner_scoped_and_inbox_ordered(): void
    {
        $me = $this->student();
        $other = $this->student();

        // For me: a resolved update, a reminder, an urgent action-required, an update.
        $resolved = $this->make($me, 'k-resolved', NotificationCategory::Update, NotificationPriority::Normal, occurred: '2026-06-18 08:00:00');
        $resolved->forceFill(['resolved_at' => now()])->save();
        $this->make($me, 'k-reminder', NotificationCategory::Reminder, NotificationPriority::High, occurred: '2026-06-18 08:10:00');
        $this->make($me, 'k-action', NotificationCategory::ActionRequired, NotificationPriority::Urgent, occurred: '2026-06-18 08:05:00');
        $this->make($me, 'k-update', NotificationCategory::Update, NotificationPriority::Normal, occurred: '2026-06-18 08:20:00');
        // Another user's notification must never appear.
        $this->make($other, 'k-foreign', NotificationCategory::ActionRequired, NotificationPriority::Urgent);

        $this->actingAsUser($me);
        $response = $this->getJson('/api/notifications')->assertOk();
        $ids = array_column($response->json('data'), 'subject_id');

        // Order: unresolved action-required, then reminder, then update, then resolved.
        $this->assertSame(['k-action', 'k-reminder', 'k-update', 'k-resolved'], $ids);
        // All four are unread (the resolved one was never read).
        $this->assertSame(4, $response->json('meta.unread_count'));
        // No foreign leakage.
        $this->assertNotContains('k-foreign', $ids);
    }

    public function test_filters_by_category_unread_and_unresolved(): void
    {
        $me = $this->student();
        $read = $this->make($me, 'k-read', NotificationCategory::Update, NotificationPriority::Normal);
        $read->forceFill(['read_at' => now()])->save();
        $resolved = $this->make($me, 'k-res', NotificationCategory::Reminder, NotificationPriority::Normal);
        $resolved->forceFill(['resolved_at' => now()])->save();
        $this->make($me, 'k-open', NotificationCategory::ActionRequired, NotificationPriority::High);

        $this->actingAsUser($me);
        // Unread = both the open action AND the resolved-but-never-read reminder
        // (read and resolved are independent). Inbox order puts the unresolved
        // action first, the resolved reminder last.
        $this->assertSame(
            ['k-open', 'k-res'],
            array_column($this->getJson('/api/notifications?unread=1')->json('data'), 'subject_id'),
        );
        // Unresolved = the open action and the read-but-unresolved update; the
        // action-required item sorts ahead of the plain update.
        $this->assertSame(
            ['k-open', 'k-read'],
            array_column($this->getJson('/api/notifications?unresolved=1')->json('data'), 'subject_id'),
        );
        $this->assertSame(
            ['k-open'],
            array_column($this->getJson('/api/notifications?category=action_required')->json('data'), 'subject_id'),
        );
    }

    public function test_unread_count_endpoint(): void
    {
        $me = $this->student();
        $this->make($me, 'a', NotificationCategory::Update, NotificationPriority::Normal);
        $read = $this->make($me, 'b', NotificationCategory::Update, NotificationPriority::Normal);
        $read->forceFill(['read_at' => now()])->save();

        $this->actingAsUser($me);
        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 1)
            ->assertJsonPath('data.unresolved', 2);
    }

    public function test_mark_read_is_independent_of_resolved(): void
    {
        $me = $this->student();
        $note = $this->make($me, 'k', NotificationCategory::ActionRequired, NotificationPriority::High);

        $this->actingAsUser($me);
        $this->patchJson("/api/notifications/{$note->public_id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true)
            ->assertJsonPath('data.is_resolved', false);

        $this->patchJson("/api/notifications/{$note->public_id}/unread")
            ->assertOk()
            ->assertJsonPath('data.is_read', false);

        // Reading never resolves — resolve stays domain-controlled.
        $this->assertNull($note->fresh()->resolved_at);
    }

    public function test_mark_all_read_only_touches_own_unread(): void
    {
        $me = $this->student();
        $other = $this->student();
        $this->make($me, 'm1', NotificationCategory::Update, NotificationPriority::Normal);
        $this->make($me, 'm2', NotificationCategory::Update, NotificationPriority::Normal);
        $foreign = $this->make($other, 'f1', NotificationCategory::Update, NotificationPriority::Normal);

        $this->actingAsUser($me);
        $this->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_read', 2);

        $this->assertSame(0, AppNotification::query()->ownedBy($me->id)->unread()->count());
        // The other account is untouched.
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_idor_cannot_read_or_mutate_another_users_notification(): void
    {
        $me = $this->student();
        $other = $this->student();
        $foreign = $this->make($other, 'secret', NotificationCategory::ActionRequired, NotificationPriority::Urgent);

        $this->actingAsUser($me);
        // Marking read a foreign notification → 404 (never reveals existence).
        $this->patchJson("/api/notifications/{$foreign->public_id}/read")->assertNotFound();
        $this->patchJson("/api/notifications/{$foreign->public_id}/unread")->assertNotFound();
        // It stays untouched.
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_response_fields_are_allowlisted(): void
    {
        $me = $this->student();
        $this->make($me, 'k', NotificationCategory::ActionRequired, NotificationPriority::High);

        $this->actingAsUser($me);
        $row = $this->getJson('/api/notifications')->json('data.0');

        $this->assertSame([
            'id', 'event_type', 'category', 'priority', 'title', 'body',
            'subject_type', 'subject_id', 'action', 'is_read', 'is_resolved',
            'occurred_at', 'read_at', 'resolved_at', 'expires_at', 'schema_version',
        ], array_keys($row));
        // No internal numeric id, storage path, checksum, or recipient id leak.
        $this->assertArrayNotHasKey('recipient_user_id', $row);
        $this->assertArrayNotHasKey('dedup_key', $row);
    }

    public function test_pagination_caps_page_size(): void
    {
        $me = $this->student();
        for ($i = 0; $i < 12; $i++) {
            $this->make($me, "n{$i}", NotificationCategory::Update, NotificationPriority::Normal, occurred: '2026-06-18 08:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00');
        }

        $this->actingAsUser($me);
        $response = $this->getJson('/api/notifications?per_page=5')->assertOk();
        $this->assertCount(5, $response->json('data'));
        $this->assertSame(12, $response->json('meta.total'));
    }

    private function make(
        User $user,
        string $subjectId,
        NotificationCategory $category,
        NotificationPriority $priority,
        ?string $occurred = null,
    ): AppNotification {
        return $this->writer->write(new NotificationIntent(
            recipient: $user,
            eventType: 'test_event',
            category: $category,
            priority: $priority,
            title: 'T',
            body: 'B',
            dedupKey: "test:{$user->id}:{$subjectId}",
            subjectType: 'test',
            subjectPublicId: $subjectId,
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_DETAIL,
            actionLabel: 'Buka',
            occurredAt: $occurred ? Carbon::parse($occurred, config('app.timezone')) : null,
        ));
    }
}
