<?php

namespace Tests\Feature\RoomManagement;

use App\Models\FacilityType;
use Database\Seeders\FacilityTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RoomManagement\Concerns\InteractsWithRoomManagement;
use Tests\TestCase;

class RoomFacilityApiTest extends TestCase
{
    use InteractsWithRoomManagement;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoomFixtures();
        $this->seed(FacilityTypeSeeder::class);
    }

    public function test_facility_types_list_predefined_first_and_custom_creation(): void
    {
        $this->actingAsSarpras();

        $types = $this->getJson('/api/room-management/facility-types')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(10, count($types));
        $this->assertTrue($types[0]['is_predefined']);

        $this->postJson('/api/room-management/facility-types', ['name' => 'Smart TV'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'smart_tv')
            ->assertJsonPath('data.is_predefined', false);

        // Same name (or slug-equivalent) is rejected with human copy.
        $this->postJson('/api/room-management/facility-types', ['name' => 'Smart TV'])
            ->assertUnprocessable();
        $this->postJson('/api/room-management/facility-types', ['name' => 'smart tv'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Fasilitas dengan nama serupa sudah ada.');
    }

    public function test_master_list_includes_is_active_and_usage_count(): void
    {
        $this->actingAsSuperAdmin();
        $proyektor = FacilityType::where('slug', 'proyektor')->firstOrFail();

        // Assign proyektor to two rooms → usage_count = 2.
        foreach ([$this->classroom, $this->labARoom] as $room) {
            \App\Models\RoomFacility::create([
                'room_id' => $room->id,
                'facility_type_id' => $proyektor->id,
                'quantity' => 1,
            ]);
        }

        $types = $this->getJson('/api/room-management/facility-types')->assertOk()->json('data');
        $row = collect($types)->firstWhere('id', $proyektor->id);

        $this->assertSame(2, $row['usage_count']);
        $this->assertTrue($row['is_active']);
    }

    public function test_active_filter_hides_deactivated_types(): void
    {
        $this->actingAsSuperAdmin();
        $kursi = FacilityType::where('slug', 'kursi')->firstOrFail();

        $this->patchJson("/api/room-management/facility-types/{$kursi->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Master list still shows it; the assignment (?active=1) list hides it.
        $all = collect($this->getJson('/api/room-management/facility-types')->json('data'));
        $active = collect($this->getJson('/api/room-management/facility-types?active=1')->json('data'));

        $this->assertTrue($all->contains('id', $kursi->id));
        $this->assertFalse($active->contains('id', $kursi->id));
    }

    public function test_rename_facility_type_and_reject_duplicate(): void
    {
        $this->actingAsSuperAdmin();
        $meja = FacilityType::where('slug', 'meja')->firstOrFail();

        $this->patchJson("/api/room-management/facility-types/{$meja->id}", ['name' => 'Meja Lipat'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Meja Lipat')
            ->assertJsonPath('data.slug', 'meja_lipat');

        // Renaming to an existing name/slug is rejected.
        $this->patchJson("/api/room-management/facility-types/{$meja->id}", ['name' => 'Kursi'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Fasilitas dengan nama serupa sudah ada.');

        $this->assertDatabaseHas('room_audit_logs', [
            'subject_type' => 'facility',
            'action' => 'type_updated',
        ]);
    }

    public function test_facility_type_in_use_cannot_be_hard_deleted_but_can_be_deactivated(): void
    {
        $this->actingAsSuperAdmin();
        $ac = FacilityType::where('slug', 'ac')->firstOrFail();
        \App\Models\RoomFacility::create([
            'room_id' => $this->classroom->id,
            'facility_type_id' => $ac->id,
            'quantity' => 1,
        ]);

        // Deactivation is the safe soft-remove; the assignment stays on the room.
        $this->patchJson("/api/room-management/facility-types/{$ac->id}", ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('room_facilities', [
            'room_id' => $this->classroom->id,
            'facility_type_id' => $ac->id,
        ]);

        // The DB restrictOnDelete guards against a hard delete while in use.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $ac->delete();
    }

    public function test_facility_master_endpoints_deny_mahasiswa(): void
    {
        $this->actingAsMahasiswa();
        $meja = FacilityType::where('slug', 'meja')->firstOrFail();
        $this->getJson('/api/room-management/facility-types')->assertForbidden();
        $this->patchJson("/api/room-management/facility-types/{$meja->id}", ['is_active' => false])->assertForbidden();
    }

    public function test_super_admin_hard_deletes_an_unused_custom_facility(): void
    {
        $this->actingAsSuperAdmin();
        $custom = FacilityType::create(['name' => 'Kamera CCTV', 'slug' => 'kamera_cctv', 'is_predefined' => false]);

        $this->deleteJson("/api/room-management/facility-types/{$custom->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Jenis fasilitas berhasil dihapus');

        $this->assertDatabaseMissing('facility_types', ['id' => $custom->id]);
        $this->assertDatabaseHas('room_audit_logs', ['subject_type' => 'facility', 'action' => 'type_deleted']);
    }

    public function test_super_admin_can_hard_delete_an_unused_predefined_facility(): void
    {
        // Primary/bawaan facilities are not untouchable when unused.
        $this->actingAsSuperAdmin();
        $speaker = FacilityType::where('slug', 'speaker')->firstOrFail();
        $this->assertTrue($speaker->is_predefined);

        $this->deleteJson("/api/room-management/facility-types/{$speaker->id}")->assertOk();
        $this->assertDatabaseMissing('facility_types', ['id' => $speaker->id]);
    }

    public function test_used_facility_cannot_be_hard_deleted_and_room_data_is_preserved(): void
    {
        $this->actingAsSuperAdmin();
        $proyektor = FacilityType::where('slug', 'proyektor')->firstOrFail();
        \App\Models\RoomFacility::create([
            'room_id' => $this->classroom->id,
            'facility_type_id' => $proyektor->id,
            'quantity' => 1,
        ]);

        $this->deleteJson("/api/room-management/facility-types/{$proyektor->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'facility_in_use');

        // Type and the room assignment both survive.
        $this->assertDatabaseHas('facility_types', ['id' => $proyektor->id]);
        $this->assertDatabaseHas('room_facilities', [
            'room_id' => $this->classroom->id,
            'facility_type_id' => $proyektor->id,
        ]);

        // Archiving (deactivate) is the safe path for a used facility.
        $this->patchJson("/api/room-management/facility-types/{$proyektor->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('room_facilities', [
            'room_id' => $this->classroom->id,
            'facility_type_id' => $proyektor->id,
        ]);
    }

    public function test_facility_dictionary_mutations_are_super_admin_only(): void
    {
        $meja = FacilityType::where('slug', 'meja')->firstOrFail();

        // No created_by/scope ownership exists, so Tendik cannot rename,
        // archive, or delete global facility types.
        foreach ([$this->actingAsSarpras(), $this->actingAsKalab(), $this->actingAsLaboran()] as $_tendik) {
            $this->patchJson("/api/room-management/facility-types/{$meja->id}", ['name' => 'Meja X'])
                ->assertForbidden()
                ->assertJsonPath('message', 'Hanya Super Admin yang dapat mengubah, mengarsipkan, atau menghapus jenis fasilitas.');
            $this->deleteJson("/api/room-management/facility-types/{$meja->id}")->assertForbidden();
        }

        // Tendik may still create a facility type while managing rooms.
        $this->actingAsSarpras();
        $this->postJson('/api/room-management/facility-types', ['name' => 'Layar Sentuh'])->assertCreated();
    }

    public function test_facility_usage_lists_rooms_with_counts_by_type(): void
    {
        $this->actingAsSuperAdmin();
        $proyektor = FacilityType::where('slug', 'proyektor')->firstOrFail();

        \App\Models\RoomFacility::create([
            'room_id' => $this->classroom->id,
            'facility_type_id' => $proyektor->id,
            'quantity' => 2,
            'condition' => 'baik',
        ]);
        \App\Models\RoomFacility::create([
            'room_id' => $this->labARoom->id,
            'facility_type_id' => $proyektor->id,
            'quantity' => 1,
        ]);

        $data = $this->getJson("/api/room-management/facility-types/{$proyektor->id}/rooms")
            ->assertOk()
            ->assertJsonPath('data.facility_type.slug', 'proyektor')
            ->json('data');

        $this->assertSame(2, $data['summary']['total']);
        $this->assertSame(1, $data['summary']['classroom']);
        $this->assertSame(1, $data['summary']['laboratory']);
        $this->assertSame(0, $data['summary']['other']);
        $this->assertCount(2, $data['rooms']);

        $codes = collect($data['rooms'])->pluck('code');
        $this->assertTrue($codes->contains($this->classroom->code));
        $this->assertTrue($codes->contains($this->labARoom->code));

        $classroomRow = collect($data['rooms'])->firstWhere('code', $this->classroom->code);
        $this->assertSame('classroom', $classroomRow['type']);
        $this->assertSame(2, $classroomRow['quantity']);
        $this->assertSame('baik', $classroomRow['condition']);
    }

    public function test_facility_usage_is_empty_for_unused_type(): void
    {
        $this->actingAsSuperAdmin();
        $unused = FacilityType::create(['name' => 'Papan Interaktif', 'slug' => 'papan_interaktif', 'is_predefined' => false]);

        $data = $this->getJson("/api/room-management/facility-types/{$unused->id}/rooms")
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['summary']['total']);
        $this->assertSame([], $data['rooms']);
    }

    public function test_facility_usage_detail_is_super_admin_only(): void
    {
        $proyektor = FacilityType::where('slug', 'proyektor')->firstOrFail();
        $this->actingAsSarpras();
        $this->getJson("/api/room-management/facility-types/{$proyektor->id}/rooms")->assertForbidden();
        $this->actingAsMahasiswa();
        $this->getJson("/api/room-management/facility-types/{$proyektor->id}/rooms")->assertForbidden();
    }

    public function test_sync_creates_updates_and_removes_facilities(): void
    {
        $this->actingAsSarpras();
        $url = "/api/room-management/rooms/{$this->classroom->id}/facilities";
        $proyektor = FacilityType::where('slug', 'proyektor')->firstOrFail();
        $kursi = FacilityType::where('slug', 'kursi')->firstOrFail();

        $this->putJson($url, ['facilities' => [
            ['facility_type_id' => $proyektor->id, 'quantity' => 1, 'condition' => 'baik'],
            ['facility_type_id' => $kursi->id, 'quantity' => 40, 'condition' => 'perlu_perbaikan', 'notes' => 'Beberapa kursi goyang.'],
        ]])->assertOk()
            ->assertJsonCount(2, 'data');

        // Second sync: update quantity, drop kursi.
        $response = $this->putJson($url, ['facilities' => [
            ['facility_type_id' => $proyektor->id, 'quantity' => 2, 'condition' => 'baik'],
        ]])->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(2, $response->json('data.0.quantity'));
        $this->assertSame('proyektor', $response->json('data.0.slug'));
        $this->assertDatabaseMissing('room_facilities', [
            'room_id' => $this->classroom->id,
            'facility_type_id' => $kursi->id,
        ]);

        $this->assertDatabaseHas('room_audit_logs', [
            'room_id' => $this->classroom->id,
            'subject_type' => 'facility',
            'action' => 'synced',
        ]);
    }

    public function test_sync_validation_rejects_bad_payloads(): void
    {
        $this->actingAsSarpras();
        $url = "/api/room-management/rooms/{$this->classroom->id}/facilities";
        $proyektor = FacilityType::where('slug', 'proyektor')->firstOrFail();

        // Duplicate facility in one payload.
        $this->putJson($url, ['facilities' => [
            ['facility_type_id' => $proyektor->id],
            ['facility_type_id' => $proyektor->id],
        ]])->assertUnprocessable();

        // Unknown condition value.
        $this->putJson($url, ['facilities' => [
            ['facility_type_id' => $proyektor->id, 'condition' => 'hancur'],
        ]])->assertUnprocessable();

        // Unknown facility type.
        $this->putJson($url, ['facilities' => [
            ['facility_type_id' => 999999],
        ]])->assertUnprocessable();
    }

    public function test_facility_permission_matrix(): void
    {
        $proyektor = FacilityType::where('slug', 'proyektor')->firstOrFail();
        $payload = ['facilities' => [['facility_type_id' => $proyektor->id, 'quantity' => 1]]];

        $this->actingAsKalab();
        $this->putJson("/api/room-management/rooms/{$this->labARoom->id}/facilities", $payload)->assertOk();
        $this->putJson("/api/room-management/rooms/{$this->labBRoom->id}/facilities", $payload)->assertNotFound();

        $this->actingAsLaboran();
        $this->putJson("/api/room-management/rooms/{$this->labBRoom->id}/facilities", $payload)->assertOk();
        $this->putJson("/api/room-management/rooms/{$this->classroom->id}/facilities", $payload)->assertNotFound();

        $this->actingAsMahasiswa();
        $this->putJson("/api/room-management/rooms/{$this->classroom->id}/facilities", $payload)->assertForbidden();
    }
}
