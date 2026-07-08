<?php

namespace Tests\Feature\RoomManagement;

use App\Models\FacilityType;
use App\Models\Room;
use App\Models\RoomBookingRequest;
use App\Models\RoomFacility;
use App\Models\RoomPhoto;
use Database\Seeders\FacilityTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RoomManagement\Concerns\InteractsWithRoomManagement;
use Tests\TestCase;

class RoomBulkDeleteApiTest extends TestCase
{
    use InteractsWithRoomManagement;
    use RefreshDatabase;

    private const URL = '/api/room-management/rooms/bulk';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoomFixtures();
    }

    private function photoRow(Room $room): RoomPhoto
    {
        return RoomPhoto::create([
            'room_id' => $room->id,
            'storage_disk' => 'local',
            'thumb_path' => "room-photos/{$room->id}/x_thumb.jpg",
            'display_path' => "room-photos/{$room->id}/x_display.jpg",
            'full_path' => null,
            'original_name' => 'x.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 1000,
            'width' => 800,
            'height' => 600,
            'checksum_sha256' => str_repeat('a', 64),
            'is_cover' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_super_admin_bulk_hard_deletes_rooms_without_bookings(): void
    {
        $this->actingAsSuperAdmin();
        $a = Room::factory()->classroom()->create();
        $b = Room::factory()->laboratory($this->labA)->create();

        $data = $this->deleteJson(self::URL, ['room_ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.summary.deleted', 2)
            ->assertJsonPath('data.summary.archived', 0)
            ->json('data');

        $this->assertCount(2, $data['deleted']);
        $this->assertDatabaseMissing('rooms', ['id' => $a->id]);
        $this->assertDatabaseMissing('rooms', ['id' => $b->id]);

        // The delete audit survives the room_audit_logs cascade (room_id null).
        $this->assertDatabaseHas('room_audit_logs', [
            'room_id' => null,
            'subject_type' => 'room',
            'subject_id' => $a->id,
            'action' => 'deleted',
        ]);
    }

    public function test_rooms_with_bookings_are_archived_and_history_is_preserved(): void
    {
        $this->actingAsSuperAdmin();
        $booking = RoomBookingRequest::factory()->create(['room_id' => $this->classroom->id]);

        $this->deleteJson(self::URL, ['room_ids' => [$this->classroom->id]])
            ->assertOk()
            ->assertJsonPath('data.summary.deleted', 0)
            ->assertJsonPath('data.summary.archived', 1)
            ->assertJsonPath('data.archived.0.reason', 'Memiliki riwayat peminjaman');

        // Room preserved but deactivated; the booking (history) is untouched.
        $this->assertDatabaseHas('rooms', ['id' => $this->classroom->id, 'is_active' => false]);
        $this->assertDatabaseHas('room_booking_requests', ['id' => $booking->id, 'room_id' => $this->classroom->id]);

        $this->assertDatabaseHas('room_audit_logs', [
            'room_id' => $this->classroom->id,
            'subject_type' => 'room',
            'action' => 'archived',
        ]);
    }

    public function test_hard_delete_cascades_photos_and_facilities_without_orphans(): void
    {
        $this->seed(FacilityTypeSeeder::class);
        $this->actingAsSuperAdmin();
        $room = Room::factory()->classroom()->create();

        $proyektor = FacilityType::where('slug', 'proyektor')->firstOrFail();
        RoomFacility::create(['room_id' => $room->id, 'facility_type_id' => $proyektor->id, 'quantity' => 1]);
        $photo = $this->photoRow($room);

        $this->deleteJson(self::URL, ['room_ids' => [$room->id]])->assertOk();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
        $this->assertDatabaseMissing('room_facilities', ['room_id' => $room->id]);
        $this->assertDatabaseMissing('room_photos', ['id' => $photo->id]);
        // The facility type dictionary is shared and must remain intact.
        $this->assertDatabaseHas('facility_types', ['id' => $proyektor->id]);
    }

    public function test_mixed_batch_deletes_unused_and_archives_booked_rooms(): void
    {
        $this->actingAsSuperAdmin();
        $unused = Room::factory()->classroom()->create();
        RoomBookingRequest::factory()->create(['room_id' => $this->classroom->id]);

        $data = $this->deleteJson(self::URL, ['room_ids' => [$unused->id, $this->classroom->id]])
            ->assertOk()
            ->assertJsonPath('data.summary.deleted', 1)
            ->assertJsonPath('data.summary.archived', 1)
            ->json('data');

        $this->assertSame($unused->id, $data['deleted'][0]['id']);
        $this->assertSame($this->classroom->id, $data['archived'][0]['id']);
        $this->assertDatabaseMissing('rooms', ['id' => $unused->id]);
        $this->assertDatabaseHas('rooms', ['id' => $this->classroom->id, 'is_active' => false]);
    }

    public function test_sarpras_can_delete_classrooms_but_a_lab_in_the_batch_fails_closed(): void
    {
        $this->actingAsSarpras();
        $classroom = Room::factory()->classroom()->create();

        // Classroom-only batch succeeds.
        $this->deleteJson(self::URL, ['room_ids' => [$classroom->id]])->assertOk();
        $this->assertDatabaseMissing('rooms', ['id' => $classroom->id]);

        // A batch containing a lab room is rejected wholesale — no partial delete.
        $survivor = Room::factory()->classroom()->create();
        $this->deleteJson(self::URL, ['room_ids' => [$survivor->id, $this->labARoom->id]])
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki akses untuk menghapus salah satu ruangan yang dipilih.');
        $this->assertDatabaseHas('rooms', ['id' => $survivor->id]);
        $this->assertDatabaseHas('rooms', ['id' => $this->labARoom->id]);
    }

    public function test_kalab_bulk_hard_deletes_own_lab_room_without_bookings(): void
    {
        $this->actingAsKalab(); // laboratory_id = labA
        $ownRoom = Room::factory()->laboratory($this->labA)->create();

        $this->deleteJson(self::URL, ['room_ids' => [$ownRoom->id]])
            ->assertOk()
            ->assertJsonPath('data.summary.deleted', 1)
            ->assertJsonPath('data.summary.archived', 0);

        $this->assertDatabaseMissing('rooms', ['id' => $ownRoom->id]);
    }

    public function test_kalab_bulk_archives_own_lab_room_with_bookings_preserving_history(): void
    {
        $this->actingAsKalab();
        $booking = RoomBookingRequest::factory()->create(['room_id' => $this->labARoom->id]);

        $this->deleteJson(self::URL, ['room_ids' => [$this->labARoom->id]])
            ->assertOk()
            ->assertJsonPath('data.summary.deleted', 0)
            ->assertJsonPath('data.summary.archived', 1)
            ->assertJsonPath('data.archived.0.reason', 'Memiliki riwayat peminjaman');

        $this->assertDatabaseHas('rooms', ['id' => $this->labARoom->id, 'is_active' => false]);
        $this->assertDatabaseHas('room_booking_requests', ['id' => $booking->id, 'room_id' => $this->labARoom->id]);
    }

    public function test_kalab_cannot_bulk_delete_other_lab_or_classroom_and_fails_closed(): void
    {
        $this->actingAsKalab(); // laboratory_id = labA
        $ownRoom = Room::factory()->laboratory($this->labA)->create();

        // Another lab's room in the batch → 403, nothing deleted.
        $this->deleteJson(self::URL, ['room_ids' => [$ownRoom->id, $this->labBRoom->id]])
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki akses untuk menghapus salah satu ruangan yang dipilih.');
        $this->assertDatabaseHas('rooms', ['id' => $ownRoom->id]);
        $this->assertDatabaseHas('rooms', ['id' => $this->labBRoom->id]);

        // A classroom in the batch → 403 too.
        $this->deleteJson(self::URL, ['room_ids' => [$ownRoom->id, $this->classroom->id]])->assertForbidden();
        $this->assertDatabaseHas('rooms', ['id' => $ownRoom->id]);
        $this->assertDatabaseHas('rooms', ['id' => $this->classroom->id]);
    }

    public function test_laboran_still_cannot_bulk_delete_rooms(): void
    {
        $this->actingAsLaboran();
        $this->deleteJson(self::URL, ['room_ids' => [$this->labARoom->id]])->assertForbidden();
        $this->assertDatabaseHas('rooms', ['id' => $this->labARoom->id]);
    }

    public function test_mahasiswa_is_denied_by_the_route(): void
    {
        $this->actingAsMahasiswa();
        $this->deleteJson(self::URL, ['room_ids' => [$this->classroom->id]])->assertForbidden();
    }

    public function test_validation_rejects_empty_oversized_and_unknown_ids(): void
    {
        $this->actingAsSuperAdmin();

        $this->deleteJson(self::URL, ['room_ids' => []])->assertUnprocessable();
        $this->deleteJson(self::URL, ['room_ids' => range(1, 51)])->assertUnprocessable();
        $this->deleteJson(self::URL, ['room_ids' => [999999]])->assertUnprocessable();
    }
}
