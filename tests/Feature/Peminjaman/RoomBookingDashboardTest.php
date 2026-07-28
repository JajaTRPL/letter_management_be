<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\RoomBookingStatus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * The dashboard feed for the three room-booking roles.
 *
 * The load-bearing assertion is that a Kepala Lab's key/return rows arrive as
 * AWARENESS, never as work: the backend forbids them from issuing keys or
 * verifying returns, so a queue of buttons they cannot press would be a lie the
 * UI tells on the backend's behalf.
 */
class RoomBookingDashboardTest extends RoomBookingApiTestCase
{
    private const URL = '/api/tendik/peminjaman-ruangan/dashboard';

    public function test_a_kepala_lab_sees_their_labs_pending_booking_as_work(): void
    {
        $lab = $this->bookingLaboratory();
        $room = $this->laboratoryRoom($lab);
        $this->roomBooking($room, $this->student(), RoomBookingStatus::Submitted);
        Sanctum::actingAs($this->reviewerUser('kepala_lab', $lab));

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame('kepala_lab', $data['role']);
        $this->assertCount(1, $data['actionable'], 'The booking the Peminjaman page shows must also reach the dashboard.');
        $this->assertSame('approval', $data['actionable'][0]['kind']);
        $this->assertTrue($data['actionable'][0]['can_act']);
        $this->assertSame('Tinjau Pengajuan', $data['actionable'][0]['action_label']);
        // The number and the list cannot disagree.
        $this->assertSame(1, $data['stats']['actionable']);
    }

    public function test_a_kepala_lab_never_receives_another_labs_bookings(): void
    {
        $mine = $this->bookingLaboratory('01');
        $theirs = $this->bookingLaboratory('02');
        $this->roomBooking($this->laboratoryRoom($theirs), $this->student(), RoomBookingStatus::Submitted);
        Sanctum::actingAs($this->reviewerUser('kepala_lab', $mine));

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertCount(0, $data['actionable']);
        $this->assertSame(0, $data['stats']['actionable']);
    }

    public function test_a_laboran_is_given_no_approvals_because_they_approve_nothing(): void
    {
        $lab = $this->bookingLaboratory();
        $this->roomBooking($this->laboratoryRoom($lab), $this->student(), RoomBookingStatus::Submitted);
        Sanctum::actingAs($this->reviewerUser('laboran', $lab));

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $kinds = collect($data['actionable'])->pluck('kind')->all();
        $this->assertNotContains('approval', $kinds, 'A Laboran can read the review queue but cannot decide.');
    }

    public function test_sarpras_is_scoped_to_classrooms(): void
    {
        $this->roomBooking($this->classroom(), $this->student(), RoomBookingStatus::Submitted);
        $this->roomBooking(
            $this->laboratoryRoom($this->bookingLaboratory()),
            $this->student(),
            RoomBookingStatus::Submitted,
        );
        Sanctum::actingAs($this->reviewerUser('sarpras'));

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertCount(1, $data['actionable'], 'Only the classroom booking belongs to Sarpras.');
        $this->assertSame('Ruang kelas', $data['scope_label']);
    }

    public function test_an_empty_queue_reports_zero_without_inventing_rows(): void
    {
        Sanctum::actingAs($this->reviewerUser('kepala_lab', $this->bookingLaboratory()));

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame(0, $data['stats']['actionable']);
        $this->assertSame(0, $data['stats']['overdue']);
        $this->assertSame(0, $data['stats']['finished_this_month']);
        $this->assertSame([], $data['actionable']);
        $this->assertSame([], $data['history']);
    }

    public function test_a_persuratan_officer_gets_an_empty_feed_rather_than_an_error(): void
    {
        // They have their own letter dashboard; a 403 here would force the
        // frontend's role switch to handle an error it does not need to.
        Sanctum::actingAs($this->bookingUser(['role' => 'tendik', 'tendik_role' => 'persuratan']));

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame([], $data['actionable']);
        $this->assertSame(0, $data['stats']['actionable']);
    }

    public function test_a_student_cannot_reach_the_feed(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'mahasiswa']));

        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_the_scope_label_names_the_lab_a_kepala_lab_is_responsible_for(): void
    {
        $lab = $this->bookingLaboratory('07');
        Sanctum::actingAs($this->reviewerUser('kepala_lab', $lab));

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertStringContainsString($lab->code, $data['scope_label']);
    }
}
