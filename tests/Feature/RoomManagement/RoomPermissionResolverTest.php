<?php

namespace Tests\Feature\RoomManagement;

use App\Enums\RoomType;
use App\Enums\UserStatus;
use App\Models\Laboratory;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomBookingReviewerResolver;
use App\Services\RoomPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomPermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    private RoomPermissionResolver $resolver;

    private Laboratory $labA;

    private Laboratory $labB;

    private Room $classroom;

    private Room $labARoom;

    private Room $labBRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(RoomPermissionResolver::class);
        $this->labA = Laboratory::create(['name' => 'Lab RPL', 'code' => 'RPL-P']);
        $this->labB = Laboratory::create(['name' => 'Lab Jaringan', 'code' => 'TAJ-P']);
        $this->classroom = Room::factory()->classroom()->create();
        $this->labARoom = Room::factory()->laboratory($this->labA)->create();
        $this->labBRoom = Room::factory()->laboratory($this->labB)->create();
    }

    public function test_super_admin_manages_every_room_and_lifecycle(): void
    {
        $admin = $this->user('super_admin');

        foreach ([$this->classroom, $this->labARoom, $this->labBRoom] as $room) {
            $this->assertTrue($this->resolver->canManageRoomInfo($admin, $room));
            $this->assertTrue($this->resolver->canManageRoomMedia($admin, $room));
            $this->assertTrue($this->resolver->canManageRoomFacilities($admin, $room));
            $this->assertTrue($this->resolver->canManageRoomTemplates($admin, $room));
            $this->assertTrue($this->resolver->canDeactivateRoom($admin, $room));
        }

        $this->assertTrue($this->resolver->canCreateRoom($admin, RoomType::Laboratory->value, $this->labA->id));
        $this->assertSame(3, $this->resolver->manageableRoomsQuery($admin)->count());
    }

    public function test_sarpras_manages_classrooms_only(): void
    {
        $sarpras = $this->user('tendik', 'sarpras');

        $this->assertTrue($this->resolver->canManageRoomInfo($sarpras, $this->classroom));
        $this->assertTrue($this->resolver->canManageRoomMedia($sarpras, $this->classroom));
        $this->assertTrue($this->resolver->canCreateRoom($sarpras, RoomType::Classroom->value));
        $this->assertTrue($this->resolver->canDeactivateRoom($sarpras, $this->classroom));

        $this->assertFalse($this->resolver->canManageRoomInfo($sarpras, $this->labARoom));
        $this->assertFalse($this->resolver->canManageRoomTemplates($sarpras, $this->labARoom));
        $this->assertFalse($this->resolver->canCreateRoom($sarpras, RoomType::Laboratory->value, $this->labA->id));

        $this->assertSame(
            [$this->classroom->id],
            $this->resolver->manageableRoomsQuery($sarpras)->pluck('id')->all()
        );
    }

    public function test_kepala_lab_edits_own_lab_only_without_lifecycle(): void
    {
        $kalab = $this->user('tendik', 'kepala_lab', $this->labA->id);

        $this->assertTrue($this->resolver->canManageRoomInfo($kalab, $this->labARoom));
        $this->assertTrue($this->resolver->canManageRoomMedia($kalab, $this->labARoom));
        $this->assertTrue($this->resolver->canManageRoomFacilities($kalab, $this->labARoom));
        $this->assertTrue($this->resolver->canManageRoomTemplates($kalab, $this->labARoom));

        // Lifecycle stays with SuperAdmin (and Sarpras for classrooms).
        $this->assertFalse($this->resolver->canCreateRoom($kalab, RoomType::Laboratory->value, $this->labA->id));
        $this->assertFalse($this->resolver->canDeactivateRoom($kalab, $this->labARoom));

        // Other lab and classroom are out of scope.
        $this->assertFalse($this->resolver->canManageRoomInfo($kalab, $this->labBRoom));
        $this->assertFalse($this->resolver->canManageRoomInfo($kalab, $this->classroom));

        $this->assertSame(
            [$this->labARoom->id],
            $this->resolver->manageableRoomsQuery($kalab)->pluck('id')->all()
        );
    }

    public function test_laboran_maintains_all_lab_data_without_lifecycle_or_classroom(): void
    {
        $laboran = $this->user('tendik', 'laboran', $this->labA->id);

        foreach ([$this->labARoom, $this->labBRoom] as $labRoom) {
            $this->assertTrue($this->resolver->canManageRoomInfo($laboran, $labRoom));
            $this->assertTrue($this->resolver->canManageRoomMedia($laboran, $labRoom));
            $this->assertTrue($this->resolver->canManageRoomFacilities($laboran, $labRoom));
            $this->assertTrue($this->resolver->canManageRoomTemplates($laboran, $labRoom));
            $this->assertFalse($this->resolver->canDeactivateRoom($laboran, $labRoom));
        }

        $this->assertFalse($this->resolver->canManageRoomInfo($laboran, $this->classroom));
        $this->assertFalse($this->resolver->canCreateRoom($laboran, RoomType::Laboratory->value, $this->labA->id));

        $this->assertEqualsCanonicalizing(
            [$this->labARoom->id, $this->labBRoom->id],
            $this->resolver->manageableRoomsQuery($laboran)->pluck('id')->all()
        );
    }

    public function test_mahasiswa_and_persuratan_have_no_management_access(): void
    {
        $mahasiswa = $this->user('mahasiswa');
        $persuratan = $this->user('tendik', 'persuratan');

        foreach ([$mahasiswa, $persuratan] as $user) {
            foreach ([$this->classroom, $this->labARoom] as $room) {
                $this->assertFalse($this->resolver->canManageRoomInfo($user, $room));
                $this->assertFalse($this->resolver->canReadRoomManagement($user, $room));
            }
            $this->assertSame(0, $this->resolver->manageableRoomsQuery($user)->count());
        }
    }

    public function test_suspended_managers_lose_management_access(): void
    {
        $suspended = $this->user('tendik', 'sarpras', null, UserStatus::Suspended);

        $this->assertFalse($this->resolver->canManageRoomInfo($suspended, $this->classroom));
        $this->assertSame(0, $this->resolver->manageableRoomsQuery($suspended)->count());
    }

    public function test_flags_payload_matches_capabilities(): void
    {
        $kalab = $this->user('tendik', 'kepala_lab', $this->labA->id);

        $this->assertSame([
            'can_edit_info' => true,
            'can_manage_media' => true,
            'can_manage_facilities' => true,
            'can_manage_templates' => true,
            'can_deactivate' => false,
        ], $this->resolver->roomManagementFlags($kalab, $this->labARoom));
    }

    public function test_booking_approval_authority_is_not_widened_by_room_management(): void
    {
        $reviewer = app(RoomBookingReviewerResolver::class);
        $laboran = $this->user('tendik', 'laboran', $this->labA->id);
        $superAdmin = $this->user('super_admin');

        $booking = \App\Models\RoomBookingRequest::factory()->create([
            'room_id' => $this->labARoom->id,
        ]);

        // Laboran manages lab room data but still cannot approve bookings;
        // SuperAdmin monitors everything but has no workflow authority.
        $this->assertFalse($reviewer->canActAsApprover($laboran, $booking));
        $this->assertFalse($reviewer->canActAsApprover($superAdmin, $booking));

        $kalab = $this->user('tendik', 'kepala_lab', $this->labA->id);
        $this->assertTrue($reviewer->canActAsApprover($kalab, $booking));
    }

    // ─────────────────────────── helpers ───────────────────────────

    private function user(
        string $role,
        ?string $tendikRole = null,
        ?int $laboratoryId = null,
        UserStatus $status = UserStatus::Active,
    ): User {
        return User::factory()->create([
            'role' => $role,
            'tendik_role' => $tendikRole,
            'laboratory_id' => $laboratoryId,
            'role_level' => $role === 'super_admin' ? 'primary' : null,
            'status' => $status,
        ]);
    }
}
