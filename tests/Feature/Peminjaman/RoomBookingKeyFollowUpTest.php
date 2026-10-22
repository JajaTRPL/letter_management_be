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
 * Key follow-up: rejected/overdue returns show the applicant's contact and can
 * be force-closed; the next usage of the room cannot receive the key until the
 * previous one is accepted back or force-closed.
 */
class RoomBookingKeyFollowUpTest extends RoomBookingApiTestCase
{
    private const OPS = '/api/tendik/peminjaman-ruangan/operations';

    public function test_rejected_return_shows_contact_and_can_be_force_closed_with_a_note(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $student = $this->studentWithPhone('081234567890');
        $occurrence = $this->approvedOccurrence($student, '2026-06-20 10:00:00', '2026-06-20 12:00:00');
        $this->issueKey($sarpras, $occurrence, 1);

        $this->at('2026-06-20 12:10:00');
        $this->actingAsUser($student);
        $this->post($this->mahasiswaUrl("/occurrences/{$occurrence->public_id}/return"), [
            'expected_occurrence_version' => 2,
            'idempotency_key' => 'followup-submit',
            'note' => 'Kunci saya titipkan di pos satpam.',
            'evidence' => UploadedFile::fake()->image('bukti.jpg', 80, 80),
        ])->assertOk();
        $this->assertDatabaseHas('room_booking_return_requests', [
            'room_booking_occurrence_id' => $occurrence->id,
            'requester_note' => 'Kunci saya titipkan di pos satpam.',
        ]);

        $this->actingAsUser($sarpras);
        $this->postJson(self::OPS."/{$occurrence->public_id}/return/reject", [
            'expected_occurrence_version' => 3,
            'expected_return_version' => 1,
            'note' => 'Foto tidak menunjukkan kunci.',
            'idempotency_key' => 'followup-reject',
        ])->assertOk();

        $item = collect($this->getJson(self::OPS.'?tab=overdue')->assertOk()->json('data'))
            ->firstWhere('occurrence_ref', $occurrence->public_id);
        $this->assertNotNull($item, 'A rejected return belongs in the follow-up tab.');
        $this->assertSame('return_rejected', $item['operational_status']);
        $this->assertTrue($item['capabilities']['can_force_close']);
        $this->assertSame($student->name, $item['booking']['applicant_name']);
        $this->assertSame('081234567890', $item['booking']['applicant_phone']);

        $this->postJson(self::OPS."/{$occurrence->public_id}/return/force-close", [
            'expected_occurrence_version' => 4,
            'idempotency_key' => 'followup-force-empty',
        ])->assertStatus(422);

        $closed = $this->postJson(self::OPS."/{$occurrence->public_id}/return/force-close", [
            'expected_occurrence_version' => 4,
            'note' => 'Sudah menghubungi peminjam, kunci diterima di ruang Sarpras.',
            'idempotency_key' => 'followup-force',
        ]);
        $this->assertSame(200, $closed->status(), $closed->getContent());
        $this->assertSame('force_closed', $closed->json('data.booking.occurrences.0.operational_status'));
        $this->assertDatabaseHas('room_booking_return_requests', [
            'room_booking_occurrence_id' => $occurrence->id,
            'status' => 'accepted',
            'is_forced' => true,
            'decision_note' => 'Sudah menghubungi peminjam, kunci diterima di ruang Sarpras.',
        ]);
        $this->assertDatabaseHas('room_booking_workflow_events', [
            'room_booking_occurrence_id' => $occurrence->id,
            'event_type' => 'return_force_closed',
        ]);
    }

    public function test_next_usage_cannot_get_the_key_until_the_previous_one_is_closed(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $first = $this->studentWithPhone('081111111111');
        $room = $this->classroom();
        $previous = $this->approvedOccurrence($first, '2026-06-20 10:00:00', '2026-06-20 12:00:00', $room);
        $next = $this->approvedOccurrence($this->student(), '2026-06-20 13:00:00', '2026-06-20 14:00:00', $room);
        $this->issueKey($sarpras, $previous, 1);

        // 13:05 — previous borrower is past due and has sent nothing.
        $this->at('2026-06-20 13:05:00');
        $this->actingAsUser($sarpras);
        $item = collect($this->getJson(self::OPS.'?tab=key_handover')->assertOk()->json('data'))
            ->firstWhere('occurrence_ref', $next->public_id);
        $this->assertFalse($item['capabilities']['can_issue_key']);
        $this->assertSame($first->name, $item['key_blocked_by']['applicant_name']);
        $this->assertSame('081111111111', $item['key_blocked_by']['applicant_phone']);

        $blocked = $this->postJson(self::OPS."/{$next->public_id}/issue-key", [
            'expected_occurrence_version' => 1,
            'idempotency_key' => 'followup-next-blocked',
        ]);
        $blocked->assertStatus(409)->assertJsonPath('code', 'previous_key_not_returned');
        $this->assertStringContainsString('081111111111', $blocked->json('message'));

        $this->postJson(self::OPS."/{$previous->public_id}/return/force-close", [
            'expected_occurrence_version' => 2,
            'note' => 'Peminjam sudah dihubungi dan mengembalikan kunci.',
            'idempotency_key' => 'followup-previous-closed',
        ])->assertOk();

        $this->postJson(self::OPS."/{$next->public_id}/issue-key", [
            'expected_occurrence_version' => 1,
            'idempotency_key' => 'followup-next-issued',
        ])->assertOk();
    }

    public function test_force_close_is_refused_while_usage_is_still_within_its_return_window(): void
    {
        $sarpras = $this->reviewerUser('sarpras');
        $occurrence = $this->approvedOccurrence($this->student(), '2026-06-20 10:00:00', '2026-06-20 12:00:00');
        $this->issueKey($sarpras, $occurrence, 1);

        $this->at('2026-06-20 12:30:00'); // ended, but the 1-hour return window is still open
        $this->actingAsUser($sarpras);
        $this->postJson(self::OPS."/{$occurrence->public_id}/return/force-close", [
            'expected_occurrence_version' => 2,
            'note' => 'Terlalu cepat.',
            'idempotency_key' => 'followup-too-early',
        ])->assertStatus(409)->assertJsonPath('code', 'force_close_not_allowed');
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
