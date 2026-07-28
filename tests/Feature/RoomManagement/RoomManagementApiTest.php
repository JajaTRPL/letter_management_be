<?php

namespace Tests\Feature\RoomManagement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\RoomManagement\Concerns\InteractsWithRoomManagement;
use Tests\TestCase;

class RoomManagementApiTest extends TestCase
{
    use InteractsWithRoomManagement;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoomFixtures();
    }

    // ─────────────────────────── create ───────────────────────────

    public function test_sarpras_creates_classroom_but_not_lab_room(): void
    {
        $this->actingAsSarpras();

        $this->postJson('/api/room-management/rooms', $this->classroomPayload('HU-901'))
            ->assertCreated()
            ->assertJsonPath('data.code', 'HU-901')
            ->assertJsonPath('data.management_flags.can_edit_info', true);

        $this->postJson('/api/room-management/rooms', $this->labPayload('LAB-901'))
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki akses untuk menambah ruangan jenis ini.');
    }

    public function test_super_admin_creates_lab_room_but_kalab_and_laboran_cannot(): void
    {
        $this->actingAsSuperAdmin();
        $this->postJson('/api/room-management/rooms', $this->labPayload('LAB-902'))->assertCreated();

        $this->actingAsKalab();
        $this->postJson('/api/room-management/rooms', $this->labPayload('LAB-903'))->assertForbidden();

        $this->actingAsLaboran();
        $this->postJson('/api/room-management/rooms', $this->labPayload('LAB-904'))->assertForbidden();
    }

    // ─────────────────────────── update ───────────────────────────

    public function test_update_scope_matrix_for_rules_and_description(): void
    {
        // Kalab edits own lab room, including the new rules field.
        $this->actingAsKalab();
        $this->patchJson("/api/room-management/rooms/{$this->labARoom->id}", $this->updatePayload($this->labARoom, [
            'rules' => 'Wajib didampingi laboran saat menggunakan alat.',
        ]))->assertOk()
            ->assertJsonPath('data.rules', 'Wajib didampingi laboran saat menggunakan alat.');

        // Other lab is invisible (404, not 403 — existence hidden).
        $this->patchJson("/api/room-management/rooms/{$this->labBRoom->id}", $this->updatePayload($this->labBRoom))
            ->assertNotFound();

        // Laboran maintains data across all labs.
        $this->actingAsLaboran();
        $this->patchJson("/api/room-management/rooms/{$this->labBRoom->id}", $this->updatePayload($this->labBRoom, [
            'description' => 'Diperbarui oleh laboran.',
        ]))->assertOk();

        // But classrooms are out of the laboran scope.
        $this->patchJson("/api/room-management/rooms/{$this->classroom->id}", $this->updatePayload($this->classroom))
            ->assertNotFound();

        $this->assertDatabaseHas('room_audit_logs', [
            'room_id' => $this->labARoom->id,
            'subject_type' => 'room',
            'action' => 'updated',
        ]);
    }

    // ─────────────────────────── lifecycle ───────────────────────────

    public function test_room_lifecycle_scope_super_admin_sarpras_and_own_lab_kepala_lab(): void
    {
        // Sarpras retires classrooms.
        $this->actingAsSarpras();
        $this->postJson("/api/room-management/rooms/{$this->classroom->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Kepala Lab may now retire rooms in their OWN laboratory.
        $this->actingAsKalab(); // laboratory_id = labA
        $this->postJson("/api/room-management/rooms/{$this->labARoom->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->postJson("/api/room-management/rooms/{$this->labARoom->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        // But not another lab's rooms nor classrooms — both hidden (404).
        $this->postJson("/api/room-management/rooms/{$this->labBRoom->id}/deactivate")->assertNotFound();
        $this->postJson("/api/room-management/rooms/{$this->classroom->id}/deactivate")->assertNotFound();

        // Laboran can read lab data but never lifecycle (403, not 404).
        $this->actingAsLaboran();
        $this->postJson("/api/room-management/rooms/{$this->labARoom->id}/deactivate")->assertForbidden();

        // SuperAdmin retains full lifecycle authority.
        $this->actingAsSuperAdmin();
        $this->postJson("/api/room-management/rooms/{$this->labBRoom->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('room_audit_logs', [
            'room_id' => $this->labARoom->id,
            'action' => 'activated',
        ]);
    }

    // ─────────────────────────── list / show scoping ───────────────────────────

    public function test_index_returns_only_manageable_rooms_with_flags(): void
    {
        $this->actingAsSarpras();
        $codes = collect($this->getJson('/api/room-management/rooms')->assertOk()->json('data'))->pluck('code');
        $this->assertTrue($codes->contains($this->classroom->code));
        $this->assertFalse($codes->contains($this->labARoom->code));

        $this->actingAsKalab();
        $codes = collect($this->getJson('/api/room-management/rooms')->assertOk()->json('data'))->pluck('code');
        $this->assertSame([$this->labARoom->code], $codes->all());

        $this->actingAsLaboran();
        $response = $this->getJson('/api/room-management/rooms')->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertEqualsCanonicalizing(
            [$this->labARoom->code, $this->labBRoom->code],
            $codes->all()
        );
        $this->assertFalse($response->json('data.0.management_flags.can_deactivate'));

        $this->actingAsSuperAdmin();
        $this->getJson('/api/room-management/rooms')->assertOk()->assertJsonPath('count', 3);
    }

    public function test_show_hides_rooms_outside_scope(): void
    {
        $this->actingAsKalab();
        $this->getJson("/api/room-management/rooms/{$this->labARoom->id}")->assertOk();
        $this->getJson("/api/room-management/rooms/{$this->labBRoom->id}")->assertNotFound();
        $this->getJson("/api/room-management/rooms/{$this->classroom->id}")->assertNotFound();
    }

    // ─────────────────────────── access & plumbing ───────────────────────────

    public function test_mahasiswa_is_rejected_by_role_middleware(): void
    {
        $this->actingAsMahasiswa();
        $this->getJson('/api/room-management/rooms')->assertForbidden();
        $this->postJson('/api/room-management/rooms', $this->classroomPayload('HU-905'))->assertForbidden();
    }

    public function test_room_management_routes_are_rate_limited(): void
    {
        $expectations = [
            'api/room-management/rooms' => 'throttle:room-manage',
            'api/room-management/rooms/{room}/photos' => 'throttle:room-media-upload',
            'api/room-management/rooms/{room}/templates' => 'throttle:room-template',
            'api/rooms/{room}/photos/{photo}/{variant}' => 'throttle:room-media-view',
        ];

        foreach ($expectations as $uri => $middleware) {
            $routes = collect(Route::getRoutes())->filter(fn ($route) => $route->uri() === $uri);
            $this->assertTrue($routes->isNotEmpty(), "Route {$uri} not found");
            $this->assertTrue(
                $routes->contains(fn ($route) => in_array($middleware, $route->gatherMiddleware(), true)),
                "Route {$uri} missing {$middleware}"
            );
        }
    }

    // ─────────────────────────── helpers ───────────────────────────

    /** @return array<string, mixed> */
    private function classroomPayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Ruang Kelas ' . $code,
            'type' => 'classroom',
            'capacity' => 40,
            'location' => 'Gedung Herman Yohanes',
            'description' => null,
            'rules' => null,
            'owning_laboratory_id' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function labPayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Ruang Lab ' . $code,
            'type' => 'laboratory',
            'capacity' => 25,
            'location' => 'Gedung Herman Yohanes',
            'description' => null,
            'rules' => null,
            'owning_laboratory_id' => $this->labA->id,
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(\App\Models\Room $room, array $overrides = []): array
    {
        return array_merge([
            'code' => $room->code,
            'name' => $room->name,
            'type' => $room->type->value,
            'capacity' => $room->capacity,
            'location' => $room->location,
            'description' => $room->description,
            'rules' => $room->rules,
            'owning_laboratory_id' => $room->owning_laboratory_id,
        ], $overrides);
    }
}
