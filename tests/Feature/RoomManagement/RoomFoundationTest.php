<?php

namespace Tests\Feature\RoomManagement;

use App\Models\FacilityType;
use App\Models\Laboratory;
use App\Models\Room;
use App\Models\RoomAuditLog;
use App\Models\RoomDocumentTemplate;
use App\Models\RoomFacility;
use App\Models\RoomPhoto;
use Database\Seeders\FacilityTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_rules_column_is_nullable_and_fillable(): void
    {
        $room = Room::factory()->classroom()->create();
        $this->assertNull($room->rules);

        $room->update(['rules' => 'Dilarang membawa makanan dan minuman.']);
        $this->assertSame('Dilarang membawa makanan dan minuman.', $room->fresh()->rules);
    }

    public function test_room_has_photos_with_cover_and_ordering(): void
    {
        $room = Room::factory()->classroom()->create();

        $second = $this->makePhoto($room, ['sort_order' => 2]);
        $cover = $this->makePhoto($room, ['sort_order' => 1, 'is_cover' => true]);

        $this->assertSame([$cover->id, $second->id], $room->photos()->pluck('id')->all());
        $this->assertTrue($room->coverPhoto->is($cover));
        $this->assertTrue($cover->room->is($room));
    }

    public function test_photo_storage_paths_are_hidden_from_serialization(): void
    {
        $room = Room::factory()->classroom()->create();
        $photo = $this->makePhoto($room);

        $serialized = $photo->toArray();
        foreach (['storage_disk', 'thumb_path', 'display_path', 'full_path'] as $secret) {
            $this->assertArrayNotHasKey($secret, $serialized);
        }
        $this->assertArrayHasKey('checksum_sha256', $serialized);
    }

    public function test_room_facilities_relations_and_unique_constraint(): void
    {
        $room = Room::factory()->classroom()->create();
        $type = FacilityType::create(['name' => 'Proyektor', 'slug' => 'proyektor', 'is_predefined' => true]);

        RoomFacility::create([
            'room_id' => $room->id,
            'facility_type_id' => $type->id,
            'quantity' => 2,
            'condition' => RoomFacility::CONDITION_BAIK,
            'notes' => 'Terpasang di plafon.',
        ]);

        $this->assertSame(1, $room->facilityItems()->count());
        $this->assertSame('Proyektor', $room->facilities()->first()->name);
        $this->assertSame(2, (int) $room->facilities()->first()->pivot->quantity);

        // Same facility type cannot be attached to the same room twice.
        $this->expectException(QueryException::class);
        RoomFacility::create([
            'room_id' => $room->id,
            'facility_type_id' => $type->id,
        ]);
    }

    public function test_template_resolution_prefers_lab_override_then_category(): void
    {
        $laboratory = Laboratory::create(['name' => 'Lab RPL', 'code' => 'RPL-T']);
        $labRoom = Room::factory()->laboratory($laboratory)->create();
        $classroom = Room::factory()->classroom()->create();

        $categoryLab = $this->makeTemplate(RoomDocumentTemplate::SCOPE_LABORATORY, null, 1);
        $labSpecific = $this->makeTemplate(RoomDocumentTemplate::SCOPE_LABORATORY, $laboratory->id, 1);
        $classroomTpl = $this->makeTemplate(RoomDocumentTemplate::SCOPE_CLASSROOM, null, 3);

        $this->assertTrue($labRoom->activeDocumentTemplate()->is($labSpecific));
        $this->assertTrue($classroom->activeDocumentTemplate()->is($classroomTpl));

        // Lab override inactive → falls back to the category-wide template.
        $labSpecific->update(['is_active' => false]);
        $this->assertTrue($labRoom->activeDocumentTemplate()->is($categoryLab));

        // No active template at all → null, callers render a friendly empty state.
        $categoryLab->update(['is_active' => false]);
        $this->assertNull($labRoom->activeDocumentTemplate());
    }

    public function test_template_storage_path_is_hidden_from_serialization(): void
    {
        $template = $this->makeTemplate(RoomDocumentTemplate::SCOPE_CLASSROOM, null, 1);

        $serialized = $template->toArray();
        $this->assertArrayNotHasKey('path', $serialized);
        $this->assertArrayNotHasKey('storage_disk', $serialized);
        $this->assertArrayHasKey('version', $serialized);
    }

    public function test_room_has_audit_logs_relation(): void
    {
        $room = Room::factory()->classroom()->create();

        RoomAuditLog::create([
            'room_id' => $room->id,
            'subject_type' => RoomAuditLog::SUBJECT_ROOM,
            'action' => 'updated',
            'created_at' => now(),
        ]);

        $this->assertSame(1, $room->auditLogs()->count());
    }

    public function test_facility_type_seeder_creates_ten_predefined_types_idempotently(): void
    {
        $this->seed(FacilityTypeSeeder::class);
        $this->assertSame(10, FacilityType::predefined()->count());
        $this->assertTrue(FacilityType::where('slug', 'papan_tulis')->exists());

        // Custom types survive a re-run untouched; no duplicates appear.
        FacilityType::create(['name' => 'Smart TV', 'slug' => 'smart_tv', 'is_predefined' => false]);
        $this->seed(FacilityTypeSeeder::class);

        $this->assertSame(10, FacilityType::predefined()->count());
        $this->assertSame(11, FacilityType::count());
    }

    // ─────────────────────────── helpers ───────────────────────────

    /** @param array<string, mixed> $overrides */
    private function makePhoto(Room $room, array $overrides = []): RoomPhoto
    {
        return RoomPhoto::create(array_merge([
            'room_id' => $room->id,
            'storage_disk' => 'local',
            'thumb_path' => 'room-photos/' . $room->id . '/t.jpg',
            'display_path' => 'room-photos/' . $room->id . '/d.jpg',
            'full_path' => null,
            'original_name' => 'ruang.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'width' => 1600,
            'height' => 900,
            'checksum_sha256' => hash('sha256', uniqid('', true)),
            'is_cover' => false,
            'sort_order' => 0,
        ], $overrides));
    }

    private function makeTemplate(string $scope, ?int $laboratoryId, int $version): RoomDocumentTemplate
    {
        return RoomDocumentTemplate::create([
            'scope' => $scope,
            'laboratory_id' => $laboratoryId,
            'storage_disk' => 'local',
            'path' => 'room-templates/' . uniqid('', true) . '.pdf',
            'original_name' => 'template.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 2048,
            'checksum_sha256' => hash('sha256', uniqid('', true)),
            'version' => $version,
            'is_active' => true,
        ]);
    }
}
