<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\RoomBookingStatus;
use App\Models\AppNotification;
use App\Models\RoomBookingOccurrence;
use App\Models\RoomBookingRequest;
use App\Models\RoomBookingWorkflowEvent as Ev;
use App\Models\User;
use App\Services\Notifications\NotificationActionRoute;
use App\Services\Notifications\NotificationIntent;
use App\Services\Notifications\NotificationProjector;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Notifications\NotificationWriter;
use App\Services\RoomBookingOccurrenceEventService;
use App\Services\RoomBookingOccurrenceService;
use App\Services\RoomBookingWorkflowAuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Peminjaman\RoomBookingApiTestCase;

/**
 * Exercises the centralized projector via the REAL production seam: creating a
 * RoomBookingWorkflowEvent (the immutable ledger) fires the observer, which
 * projects the C7N1 matrix. Recipient scope, dedup, resolution, supersession,
 * privacy, atomicity, and failure isolation are all asserted here.
 */
class NotificationRoomBookingMatrixTest extends RoomBookingApiTestCase
{
    private RoomBookingWorkflowAuditService $audit;

    private RoomBookingOccurrenceEventService $occurrenceEvents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = app(RoomBookingWorkflowAuditService::class);
        $this->occurrenceEvents = app(RoomBookingOccurrenceEventService::class);
    }

    // ── recipient + role scope ────────────────────────────────────────────

    public function test_classroom_submission_notifies_only_the_scoped_sarpras(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $laboran = $this->reviewerUser('laboran', $this->bookingLaboratory('SCOPE'));
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);

        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');

        $this->assertRecipients('booking-review:', [$sarpras->id]);
        // The applicant is NOT told about their own successful submission.
        $this->assertNoNotificationFor($student->id, 'booking-review:');
        // A lab-scoped Laboran never sees a classroom booking.
        $this->assertNoNotificationFor($laboran->id, 'booking-review:');

        $note = AppNotification::where('recipient_user_id', $sarpras->id)->firstOrFail();
        $this->assertSame(NotificationCategory::ActionRequired->value, $note->category->value);
        $this->assertSame('sarpras.booking.review', $note->action_route_key);
        $this->assertSame('booking', $note->subject_type);
        $this->assertSame((string) $booking->id, $note->subject_public_id);
    }

    public function test_laboratory_submission_notifies_the_owning_kepala_lab_not_other_labs(): void
    {
        $lab = $this->bookingLaboratory('A');
        $otherLab = $this->bookingLaboratory('B');
        $kalab = $this->reviewerUser('kepala_lab', $lab);
        $otherKalab = $this->reviewerUser('kepala_lab', $otherLab);
        $laboran = $this->reviewerUser('laboran', $lab);
        $student = $this->student();
        $booking = $this->roomBooking($this->laboratoryRoom($lab), $student, RoomBookingStatus::Submitted);

        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');

        $this->assertRecipients('booking-review:', [$kalab->id]);
        $this->assertNoNotificationFor($otherKalab->id, 'booking-review:');
        // Laboran must never receive routine booking-approval/review notifications.
        $this->assertNoNotificationFor($laboran->id, 'booking-review:');
        $this->assertSame(
            'kalab.booking.review',
            AppNotification::where('recipient_user_id', $kalab->id)->value('action_route_key'),
        );
    }

    public function test_revision_notifies_the_applicant_and_resolves_the_reviewer_action(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);
        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');
        $this->assertRecipients('booking-review:', [$sarpras->id]);

        $booking->forceFill(['status' => RoomBookingStatus::RevisionRequested])->save();
        $this->emit($booking, Ev::EVENT_REVISION_REQUESTED, $sarpras, 'revision');

        // Applicant now has an action-required revision notification…
        $revision = AppNotification::where('recipient_user_id', $student->id)
            ->where('dedup_key', 'like', 'booking-revision:%')->firstOrFail();
        $this->assertSame(NotificationCategory::ActionRequired->value, $revision->category->value);
        $this->assertSame('mahasiswa.booking.detail', $revision->action_route_key);
        // …and the reviewer's earlier review action is resolved (no longer pending).
        $this->assertNotNull(
            AppNotification::where('recipient_user_id', $sarpras->id)->value('resolved_at'),
        );
    }

    public function test_resubmission_resolves_the_applicant_revision_and_reopens_review(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::RevisionRequested);
        $this->emit($booking, Ev::EVENT_REVISION_REQUESTED, $sarpras, 'revision', iteration: 1);
        $this->assertNotNull(AppNotification::where('recipient_user_id', $student->id)->value('id'));

        $booking->forceFill(['status' => RoomBookingStatus::Submitted, 'submission_iteration' => 2])->save();
        $this->emit($booking, Ev::EVENT_BOOKING_RESUBMITTED, $student, 'resubmit', iteration: 2);

        // The applicant's revision action is resolved by the valid resubmission.
        $this->assertNotNull(
            AppNotification::where('recipient_user_id', $student->id)
                ->where('dedup_key', 'like', 'booking-revision:%')->value('resolved_at'),
        );
        // A fresh review item exists for the reviewer at the new iteration.
        $this->assertDatabaseHas('app_notifications', [
            'recipient_user_id' => $sarpras->id,
            'dedup_key' => "booking-review:{$booking->id}:iter:2",
            'resolved_at' => null,
        ]);
    }

    public function test_approval_notifies_applicant_and_resolves_review(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);
        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');

        $booking->forceFill(['status' => RoomBookingStatus::Approved])->save();
        $this->emit($booking, Ev::EVENT_BOOKING_APPROVED, $sarpras, 'approved');

        $approved = AppNotification::where('recipient_user_id', $student->id)
            ->where('dedup_key', "booking-approved:{$booking->id}")->firstOrFail();
        $this->assertSame(NotificationCategory::Update->value, $approved->category->value);
        $this->assertNotNull(
            AppNotification::where('recipient_user_id', $sarpras->id)->value('resolved_at'),
        );
    }

    public function test_direct_withdrawal_resolves_review_without_self_notifying_the_applicant(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);
        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');

        $booking->forceFill(['status' => RoomBookingStatus::Cancelled])->save();
        $this->emit($booking, Ev::EVENT_BOOKING_WITHDRAWN, $student, 'withdrawn');

        // Reviewer's item resolved; the applicant gets no cancellation notice for
        // their own immediate action.
        $this->assertNotNull(AppNotification::where('recipient_user_id', $sarpras->id)->value('resolved_at'));
        $this->assertSame(0, AppNotification::where('recipient_user_id', $student->id)->count());
    }

    public function test_review_started_produces_no_notification(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);
        $this->emit($booking, Ev::EVENT_REVIEW_STARTED, $sarpras, 'review-started');

        $this->assertSame(0, AppNotification::count());
    }

    // ── returns: operational owner scope + supersession ───────────────────

    public function test_return_submitted_notifies_the_scoped_operational_owner(): void
    {
        $lab = $this->bookingLaboratory('R');
        $laboran = $this->reviewerUser('laboran', $lab);
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $occurrence = $this->approvedOccurrence($this->laboratoryRoom($lab), $student);

        $this->emitOccurrence($occurrence, Ev::EVENT_RETURN_SUBMITTED, $student);

        // Own-lab Laboran gets the verification action; classroom Sarpras does not.
        $this->assertRecipients('return-verify:', [$laboran->id]);
        $this->assertNoNotificationFor($sarpras->id, 'return-verify:');
    }

    public function test_full_return_cycle_resolves_and_reopens_verification_cleanly(): void
    {
        // Real return flow: submit → revise → resubmit → accept. The verify item
        // is resolved by each owner decision and a fresh one opens on resubmit;
        // at the end nothing is left pending and history stays queryable.
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $occurrence = $this->approvedOccurrence($this->classroom(), $student);
        $this->issueKey($occurrence, $sarpras);

        Carbon::setTestNow(
            Carbon::parse('2026-06-20 12:05:00', config('app.timezone')),
        );
        $this->actingAsUser($student);
        $this->postReturn($occurrence, 2, 'c7n-return-1')->assertOk();
        $first = AppNotification::where('recipient_user_id', $sarpras->id)
            ->where('dedup_key', 'like', 'return-verify:%')->firstOrFail();
        $this->assertNull($first->resolved_at);

        $this->actingAsUser($sarpras);
        $this->postJson($this->decisionUrl($occurrence, 'revise'), [
            'expected_occurrence_version' => 3,
            'expected_return_version' => 1,
            'note' => 'Foto kurang jelas.',
            'idempotency_key' => 'c7n-revise',
        ])->assertOk();

        // Revision decision resolves the owner's verify item and opens a
        // revision action for the applicant.
        $this->assertNotNull($first->fresh()->resolved_at);
        $this->assertDatabaseHas('app_notifications', [
            'recipient_user_id' => $student->id,
            'subject_public_id' => $occurrence->public_id,
            'event_type' => Ev::EVENT_RETURN_REVISION_REQUESTED,
            'resolved_at' => null,
        ]);

        $this->actingAsUser($student);
        $this->postReturn($occurrence, 4, 'c7n-return-2')->assertOk();

        // Resubmission resolves the applicant revision and opens exactly one
        // fresh verify item; the history of both verify items is preserved.
        $this->assertNotNull(
            AppNotification::where('recipient_user_id', $student->id)
                ->where('event_type', Ev::EVENT_RETURN_REVISION_REQUESTED)->value('resolved_at'),
        );
        $this->assertSame(
            1,
            AppNotification::where('recipient_user_id', $sarpras->id)
                ->where('dedup_key', 'like', 'return-verify:%')
                ->whereNull('resolved_at')->count(),
        );
        $this->assertSame(
            2,
            AppNotification::where('recipient_user_id', $sarpras->id)
                ->where('dedup_key', 'like', 'return-verify:%')->count(),
        );
    }

    public function test_writer_supersedes_an_unresolved_item_and_keeps_history(): void
    {
        // A direct supersession: two intents whose keys differ but share a
        // supersession prefix. The newer resolves + links the older.
        $writer = app(NotificationWriter::class);
        $sarpras = $this->reviewerUser('sarpras');

        $old = $writer->write($this->verifyIntent($sarpras, 'occ-1', 'ref-a'));
        $new = $writer->write($this->verifyIntent($sarpras, 'occ-1', 'ref-b', supersede: true));

        $old->refresh();
        $this->assertSame($new->id, $old->superseded_by_id);
        $this->assertNotNull($old->resolved_at);
        $this->assertNull($new->resolved_at);
        // History remains queryable — the old row is not deleted.
        $this->assertDatabaseHas('app_notifications', ['id' => $old->id]);
    }

    private function verifyIntent(User $recipient, string $occ, string $ref, bool $supersede = false): NotificationIntent
    {
        return new NotificationIntent(
            recipient: $recipient,
            eventType: Ev::EVENT_RETURN_SUBMITTED,
            category: NotificationCategory::ActionRequired,
            priority: NotificationPriority::Normal,
            title: 'Verifikasi pengembalian',
            body: 'Menunggu verifikasi.',
            dedupKey: "return-verify:{$occ}:{$ref}",
            subjectType: 'occurrence',
            subjectPublicId: $occ,
            actionRouteKey: NotificationActionRoute::SARPRAS_OPERATIONS,
            actionLabel: 'Verifikasi',
            supersedesDedupPrefixes: $supersede ? ["return-verify:{$occ}"] : [],
        );
    }

    public function test_return_accepted_resolves_owner_and_notifies_applicant(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $occurrence = $this->approvedOccurrence($this->classroom(), $student);
        $this->emitOccurrence($occurrence, Ev::EVENT_RETURN_SUBMITTED, $student);

        $this->emitOccurrence($occurrence, Ev::EVENT_RETURN_ACCEPTED, $sarpras);

        $this->assertNotNull(
            AppNotification::where('recipient_user_id', $sarpras->id)
                ->where('dedup_key', 'like', 'return-verify:%')->value('resolved_at'),
        );
        $this->assertDatabaseHas('app_notifications', [
            'recipient_user_id' => $student->id,
            'dedup_key' => "return-accepted:{$occurrence->public_id}",
        ]);
    }

    // ── dedup / read vs resolved ──────────────────────────────────────────

    public function test_repeated_event_processing_is_idempotent(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);

        // Same semantic event three times (retries / at-least-once processing).
        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');
        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');
        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');

        $this->assertSame(1, AppNotification::where('recipient_user_id', $sarpras->id)->count());
    }

    public function test_read_and_resolved_are_independent(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);
        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');
        $note = AppNotification::where('recipient_user_id', $sarpras->id)->firstOrFail();

        // Marking read leaves it unresolved.
        $note->forceFill(['read_at' => now()])->save();
        $this->assertTrue($note->fresh()->isRead());
        $this->assertFalse($note->fresh()->isResolved());

        // Resolving via a domain decision leaves the read flag as-is.
        $booking->forceFill(['status' => RoomBookingStatus::Approved])->save();
        $this->emit($booking, Ev::EVENT_BOOKING_APPROVED, $sarpras, 'approved');
        $this->assertTrue($note->fresh()->isRead());
        $this->assertTrue($note->fresh()->isResolved());
    }

    // ── privacy allowlist ─────────────────────────────────────────────────

    public function test_notification_body_carries_no_private_metadata(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $booking = $this->roomBooking(
            $this->classroom(),
            $student,
            RoomBookingStatus::Submitted,
            attributes: ['purpose' => 'SECRET-PURPOSE-DO-NOT-LEAK'],
        );
        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');

        $note = AppNotification::where('recipient_user_id', $sarpras->id)->firstOrFail();
        $blob = strtolower($note->title.' '.$note->body);
        $this->assertStringNotContainsString('secret-purpose', $blob);
        $this->assertStringNotContainsString('storage', $blob);
        $this->assertStringNotContainsString('checksum', $blob);
        // The action target is a route KEY, never a raw/absolute URL.
        $this->assertStringNotContainsString('http', (string) $note->action_route_key);
    }

    // ── atomicity + failure isolation ─────────────────────────────────────

    public function test_notification_projection_failure_does_not_break_the_workflow(): void
    {
        // A projector whose write path is broken must not surface as an error to
        // the caller, and the workflow event itself is still committed.
        $this->app->bind(NotificationProjector::class, function ($app) {
            return new class($app->make(NotificationWriter::class), $app->make(NotificationRecipientResolver::class)) extends NotificationProjector
            {
                public function projectRoomBookingEvent(Ev $event): void
                {
                    // Simulate an internal defect; safely() must swallow it.
                    $this->safely(function (): void {
                        throw new \RuntimeException('boom');
                    });
                }
            };
        });

        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);
        $event = $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');

        $this->assertDatabaseHas('room_booking_workflow_events', ['id' => $event->id]);
        $this->assertSame(0, AppNotification::count());
    }

    public function test_no_notification_survives_a_rolled_back_mutation(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);

        // The observer projects synchronously inside the transaction, so a
        // rollback discards the event AND its notification together.
        try {
            DB::transaction(function () use ($booking, $student): void {
                $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, AppNotification::where('recipient_user_id', $sarpras->id)->count());
        $this->assertSame(0, Ev::where('room_booking_request_id', $booking->id)->count());
    }

    public function test_missing_recipient_raises_a_superadmin_health_alert(): void
    {
        $admin = $this->superAdmin();
        // No active Sarpras exists for this classroom booking.
        $student = $this->student();
        $booking = $this->roomBooking($this->classroom(), $student, RoomBookingStatus::Submitted);
        $this->emit($booking, Ev::EVENT_BOOKING_SUBMITTED, $student, 'submitted');

        $health = AppNotification::where('recipient_user_id', $admin->id)->firstOrFail();
        $this->assertSame(NotificationCategory::System->value, $health->category->value);
        $this->assertSame('superadmin.health', $health->action_route_key);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function emit(
        RoomBookingRequest $booking,
        string $eventType,
        User $actor,
        string $note,
        int $iteration = 1,
    ): Ev {
        return $this->audit->record(
            $booking->fresh()->load('room'),
            $eventType,
            $actor,
            null,
            $booking->fresh()->status->value,
            1,
            2,
            $iteration,
            null,
            $eventType === Ev::EVENT_CANCELLATION_REQUESTED ? ['cancellation_request_id' => 7] : [],
        );
    }

    private function emitOccurrence(RoomBookingOccurrence $occurrence, string $eventType, User $actor): Ev
    {
        return $this->occurrenceEvents->record(
            $occurrence->fresh()->load('booking.room'),
            $eventType,
            $actor,
        );
    }

    private function approvedOccurrence($room, User $student): RoomBookingOccurrence
    {
        $booking = $this->roomBooking($room, $student, RoomBookingStatus::Approved);

        return app(RoomBookingOccurrenceService::class)->ensureLegacyOccurrence($booking)
            ->load('booking.room');
    }

    private function issueKey(RoomBookingOccurrence $occurrence, User $owner): void
    {
        $this->actingAsUser($owner);
        $this->postJson(
            "/api/tendik/peminjaman-ruangan/operations/{$occurrence->public_id}/issue-key",
            ['expected_occurrence_version' => 1, 'idempotency_key' => 'c7n-issue-'.substr($occurrence->public_id, 0, 12)],
        )->assertOk();
    }

    private function postReturn(RoomBookingOccurrence $occurrence, int $version, string $key)
    {
        return $this->post(
            "/api/mahasiswa/peminjaman-ruangan/occurrences/{$occurrence->public_id}/return",
            [
                'expected_occurrence_version' => $version,
                'idempotency_key' => $key,
                'evidence' => UploadedFile::fake()->image('bukti.jpg', 80, 80),
            ],
        );
    }

    private function decisionUrl(RoomBookingOccurrence $occurrence, string $decision): string
    {
        return "/api/tendik/peminjaman-ruangan/operations/{$occurrence->public_id}/return/{$decision}";
    }

    /** @param list<int> $expectedUserIds */
    private function assertRecipients(string $dedupPrefix, array $expectedUserIds): void
    {
        $actual = AppNotification::where('dedup_key', 'like', $dedupPrefix.'%')
            ->pluck('recipient_user_id')->sort()->values()->all();
        sort($expectedUserIds);
        $this->assertSame($expectedUserIds, $actual);
    }

    private function assertNoNotificationFor(int $userId, string $dedupPrefix): void
    {
        $this->assertSame(0, AppNotification::where('recipient_user_id', $userId)
            ->where('dedup_key', 'like', $dedupPrefix.'%')->count());
    }
}
