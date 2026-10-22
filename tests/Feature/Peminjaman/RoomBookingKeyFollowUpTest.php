<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\MahasiswaProfile;
use App\Models\RoomBookingOccurrence;
use App\Models\User;
use App\Services\RoomBookingOccurrenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Key return rules: inside the 1-hour window a rejected return can be resent
 * until accepted; past the window with no accepted return the usage closes as
 * "tidak mengembalikan kunci" (staff get the applicant's contact) and the next
 * usage of the room is not blocked.
 */
class RoomBookingKeyFollowUpTest extends RoomBookingApiTestCase
{
    private const OPS = '/api/tendik/peminjaman-ruangan/operations';

    public function test_rejected_return_can_be_resent_inside_the_window_and_then_accepted(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->studentWithPhone('081234567890');
        $occurrence = $this->approvedOccurrence($student, '2026-06-20 10:00:00', '2026-06-20 12:00:00');
        $this->issueKey($sarpras, $occurrence, 1);

        $this->at('2026-06-20 12:10:00');
        $this->submitReturn($student, $occurrence, 2, 'first', 'Kunci di pos satpam.');

        $this->actingAsUser($sarpras);
        $verifying = collect($this->getJson(self::OPS.'?tab=returns')->assertOk()->json('data'))
            ->firstWhere('occurrence_ref', $occurrence->public_id);
        $this->assertSame('Kunci di pos satpam.', $verifying['return']['requester_note']);

        $this->postJson(self::OPS."/{$occurrence->public_id}/return/reject", [
            'expected_occurrence_version' => 3,
            'expected_return_version' => 1,
            'note' => 'Foto tidak menunjukkan kunci.',
            'idempotency_key' => 'followup-reject',
        ])->assertOk();

        $item = collect($this->getJson(self::OPS.'?tab=overdue')->assertOk()->json('data'))
            ->firstWhere('occurrence_ref', $occurrence->public_id);
        $this->assertSame('return_rejected', $item['operational_status']);
        $this->assertSame('081234567890', $item['booking']['applicant_phone']);
        $this->assertSame('Foto tidak menunjukkan kunci.', $item['return']['decision_note']);

        // Still inside the window: the applicant resends.
        $this->at('2026-06-20 12:40:00');
        $this->actingAsUser($student);
        $detail = $this->getJson($this->mahasiswaUrl("/requests/{$occurrence->booking->id}"))->assertOk();
        $this->assertTrue($detail->json('data.occurrences.0.capabilities.can_submit_return'));
        $this->submitReturn($student, $occurrence, 4, 'second', 'Foto ulang di meja Sarpras.');

        $this->actingAsUser($sarpras);
        $accepted = $this->postJson(self::OPS."/{$occurrence->public_id}/return/accept", [
            'expected_occurrence_version' => 5,
            'expected_return_version' => 1,
            'idempotency_key' => 'followup-accept',
        ]);
        $this->assertSame(200, $accepted->status(), $accepted->getContent());
        $this->assertSame('returned_on_time', $accepted->json('data.booking.occurrences.0.operational_status'));
    }

    public function test_past_the_window_the_usage_closes_as_not_returned_and_the_next_usage_is_not_blocked(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $first = $this->studentWithPhone('081111111111');
        $room = $this->classroom();
        $previous = $this->approvedOccurrence($first, '2026-06-20 10:00:00', '2026-06-20 12:00:00', $room);
        $next = $this->approvedOccurrence($this->student(), '2026-06-20 13:00:00', '2026-06-20 14:00:00', $room);
        $this->issueKey($sarpras, $previous, 1);

        // 13:05 — past the 13:00 deadline, nothing was sent.
        $this->at('2026-06-20 13:05:00');
        $this->actingAsUser($sarpras);
        $item = collect($this->getJson(self::OPS.'?tab=overdue')->assertOk()->json('data'))
            ->firstWhere('occurrence_ref', $previous->public_id);
        $this->assertSame('not_returned', $item['operational_status']);
        $this->assertSame($first->name, $item['booking']['applicant_name']);
        $this->assertSame('081111111111', $item['booking']['applicant_phone']);
        $this->assertFalse($item['capabilities']['can_verify_return']);

        // The applicant can no longer send evidence.
        $this->actingAsUser($first);
        $this->post($this->mahasiswaUrl("/occurrences/{$previous->public_id}/return"), [
            'expected_occurrence_version' => 2,
            'idempotency_key' => 'followup-too-late',
            'evidence' => UploadedFile::fake()->image('bukti.jpg', 80, 80),
        ])->assertStatus(409)->assertJsonPath('code', 'return_window_closed');

        // The next borrower still gets the key.
        $this->actingAsUser($sarpras);
        $nextItem = collect($this->getJson(self::OPS.'?tab=key_handover')->assertOk()->json('data'))
            ->firstWhere('occurrence_ref', $next->public_id);
        $this->assertTrue($nextItem['capabilities']['can_issue_key']);
        $this->postJson(self::OPS."/{$next->public_id}/issue-key", [
            'expected_occurrence_version' => 1,
            'idempotency_key' => 'followup-next-issued',
        ])->assertOk();
    }

    public function test_rejecting_after_the_window_closes_the_usage_as_not_returned(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->student();
        $occurrence = $this->approvedOccurrence($student, '2026-06-20 10:00:00', '2026-06-20 12:00:00');
        $this->issueKey($sarpras, $occurrence, 1);

        $this->at('2026-06-20 12:50:00');
        $this->submitReturn($student, $occurrence, 2, 'late-check', null);

        // Staff only look at it after the 13:00 deadline and reject.
        $this->at('2026-06-20 13:20:00');
        $this->actingAsUser($sarpras);
        $rejected = $this->postJson(self::OPS."/{$occurrence->public_id}/return/reject", [
            'expected_occurrence_version' => 3,
            'expected_return_version' => 1,
            'note' => 'Foto buram.',
            'idempotency_key' => 'followup-late-reject',
        ])->assertOk();
        $this->assertSame('not_returned', $rejected->json('data.booking.occurrences.0.operational_status'));
        $this->assertFalse($rejected->json('data.booking.occurrences.0.capabilities.can_submit_return'));
    }

    public function test_usage_ending_without_key_handover_closes_as_key_not_issued(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $occurrence = $this->approvedOccurrence($this->student(), '2026-06-20 10:00:00', '2026-06-20 12:00:00');
        $scanner = app(\App\Services\Notifications\RoomBookingReminderScanner::class);

        // Usage has started and the key is still not marked: staff are told.
        $scanner->scan(Carbon::parse('2026-06-20 10:01:00', config('app.timezone')));
        $this->assertDatabaseHas('app_notifications', [
            'recipient_user_id' => $sarpras->id,
            'dedup_key' => "key-not-issued:{$occurrence->public_id}",
        ]);

        // After the usage ends it is closed — not "late", not handed over anymore.
        $this->at('2026-06-20 12:05:00');
        $this->actingAsUser($sarpras);
        $all = collect($this->getJson(self::OPS.'?tab=all')->assertOk()->json('data'))
            ->firstWhere('occurrence_ref', $occurrence->public_id);
        $this->assertSame('key_not_issued', $all['operational_status']);
        $this->assertFalse($all['capabilities']['can_issue_key']);
        $this->assertNull(collect($this->getJson(self::OPS.'?tab=key_handover')->json('data'))
            ->firstWhere('occurrence_ref', $occurrence->public_id));

        $this->postJson(self::OPS."/{$occurrence->public_id}/issue-key", [
            'expected_occurrence_version' => 1,
            'idempotency_key' => 'followup-late-issue',
        ])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    }

    public function test_marking_the_key_as_handed_over_retires_the_started_reminder(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $occurrence = $this->approvedOccurrence($this->student(), '2026-06-20 10:00:00', '2026-06-20 12:00:00');
        app(\App\Services\Notifications\RoomBookingReminderScanner::class)
            ->scan(Carbon::parse('2026-06-20 10:01:00', config('app.timezone')));

        $this->at('2026-06-20 10:10:00');
        $this->issueKey($sarpras, $occurrence, 1);

        $this->assertNotNull(\App\Models\AppNotification::where('dedup_key', "key-not-issued:{$occurrence->public_id}")->value('resolved_at'));
    }

    private function submitReturn(User $student, RoomBookingOccurrence $occurrence, int $version, string $key, ?string $note): void
    {
        $this->actingAsUser($student);
        $payload = [
            'expected_occurrence_version' => $version,
            'idempotency_key' => "followup-submit-{$key}",
            'evidence' => UploadedFile::fake()->image('bukti.jpg', 80, 80),
        ];
        if ($note !== null) {
            $payload['note'] = $note;
        }
        $this->post($this->mahasiswaUrl("/occurrences/{$occurrence->public_id}/return"), $payload)->assertOk();
    }

    private function studentWithPhone(string $phone): User
    {
        $student = $this->student();
        MahasiswaProfile::updateOrCreate(['user_id' => $student->id], ['no_hp' => $phone]);

        return $student->fresh();
    }

    private function approvedOccurrence(User $student, string $start, string $end, $room = null): RoomBookingOccurrence
    {
        $booking = $this->roomBooking($room ?? $this->classroom(), $student, RoomBookingStatus::Approved, $start, $end);

        return app(RoomBookingOccurrenceService::class)->ensureLegacyOccurrence($booking);
    }

    private function issueKey(User $actor, RoomBookingOccurrence $occurrence, int $version): void
    {
        $this->actingAsUser($actor);
        $this->postJson(self::OPS."/{$occurrence->public_id}/issue-key", [
            'expected_occurrence_version' => $version,
            'idempotency_key' => 'issue-'.substr($occurrence->public_id, 0, 20),
        ])->assertOk();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, config('app.timezone')));
    }
}
