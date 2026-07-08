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
