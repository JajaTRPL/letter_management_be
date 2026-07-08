<?php

namespace Tests\Feature\Peminjaman;

use App\Enums\UserStatus;
use App\Models\RoomBookingRequest;
use App\Services\RoomBookingReviewerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomBookingReviewerResolverTest extends TestCase
{
    use RefreshDatabase;
    use RoomBookingTestHelpers;

    private RoomBookingReviewerResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(RoomBookingReviewerResolver::class);
    }

    public function test_sarpras_can_act_on_classroom_but_not_laboratory(): void
    {
        $laboratory = $this->bookingLaboratory();
        $sarpras = $this->reviewerUser('sarpras');
        $classroomBooking = $this->roomBooking($this->classroom());
        $labBooking = $this->roomBooking($this->laboratoryRoom($laboratory));

        $this->assertTrue($this->resolver->canRead($sarpras, $classroomBooking));
        $this->assertTrue($this->resolver->canActAsApprover($sarpras, $classroomBooking));
        $this->assertFalse($this->resolver->canRead($sarpras, $labBooking));
        $this->assertFalse($this->resolver->canActAsApprover($sarpras, $labBooking));
    }

    public function test_kepala_lab_can_act_only_on_own_laboratory(): void
    {
        $ownLaboratory = $this->bookingLaboratory('OWN');
        $otherLaboratory = $this->bookingLaboratory('OTHER');
        $kepalaLab = $this->reviewerUser('kepala_lab', $ownLaboratory);
        $ownBooking = $this->roomBooking($this->laboratoryRoom($ownLaboratory));
        $otherBooking = $this->roomBooking($this->laboratoryRoom($otherLaboratory));

        $this->assertTrue($this->resolver->canActAsApprover($kepalaLab, $ownBooking));
        $this->assertFalse($this->resolver->canActAsApprover($kepalaLab, $otherBooking));
    }

    public function test_laboran_has_scoped_read_only_access(): void
    {
        $ownLaboratory = $this->bookingLaboratory('OWN');
        $otherLaboratory = $this->bookingLaboratory('OTHER');
        $laboran = $this->reviewerUser('laboran', $ownLaboratory);
        $ownBooking = $this->roomBooking($this->laboratoryRoom($ownLaboratory));
        $otherBooking = $this->roomBooking($this->laboratoryRoom($otherLaboratory));

        $this->assertTrue($this->resolver->canRead($laboran, $ownBooking));
        $this->assertFalse($this->resolver->canActAsApprover($laboran, $ownBooking));
        $this->assertFalse($this->resolver->canRead($laboran, $otherBooking));
    }

    public function test_persuratan_mahasiswa_super_admin_and_inactive_tendik_have_no_reviewer_scope(): void
    {
        $booking = $this->roomBooking($this->classroom());
        $persuratan = $this->reviewerUser('persuratan');
        $mahasiswa = $this->bookingUser();
        $superAdmin = $this->bookingUser(['role' => 'super_admin']);
        $inactiveSarpras = $this->reviewerUser('sarpras');
        $inactiveSarpras->update(['status' => UserStatus::Suspended]);

        foreach ([$persuratan, $mahasiswa, $superAdmin, $inactiveSarpras->fresh()] as $user) {
            $this->assertFalse($this->resolver->canReadReviewQueue($user));
            $this->assertFalse($this->resolver->canActAsApprover($user, $booking));
        }
    }

    public function test_reviewable_scope_matches_role_and_laboratory_boundaries(): void
    {
        $ownLaboratory = $this->bookingLaboratory('OWN');
        $otherLaboratory = $this->bookingLaboratory('OTHER');
        $classroomBooking = $this->roomBooking($this->classroom());
        $ownLabBooking = $this->roomBooking($this->laboratoryRoom($ownLaboratory));
        $otherLabBooking = $this->roomBooking($this->laboratoryRoom($otherLaboratory));

        $sarprasIds = $this->resolver
            ->scopeReviewableBookings(
                RoomBookingRequest::query(),
                $this->reviewerUser('sarpras'),
            )
            ->pluck('id')
            ->all();
        $laboranIds = $this->resolver
            ->scopeReviewableBookings(
                RoomBookingRequest::query(),
                $this->reviewerUser('laboran', $ownLaboratory),
            )
            ->pluck('id')
            ->all();

        $this->assertSame([$classroomBooking->id], $sarprasIds);
        $this->assertSame([$ownLabBooking->id], $laboranIds);
        $this->assertNotContains($otherLabBooking->id, $laboranIds);
    }

    public function test_active_kepala_lab_resolution_uses_existing_user_scope_fields(): void
    {
        $laboratory = $this->bookingLaboratory();
        $room = $this->laboratoryRoom($laboratory);
        $active = $this->reviewerUser('kepala_lab', $laboratory);
        $this->reviewerUser('laboran', $laboratory);

        $this->assertTrue($this->resolver->findActiveKepalaLab($room)->is($active));

        $active->update(['status' => UserStatus::Suspended]);

        $this->assertNull($this->resolver->findActiveKepalaLab($room));
    }
}
