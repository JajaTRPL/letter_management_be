<?php

namespace Tests\Feature\RoomManagement;

use App\Models\FacilityType;
use App\Models\RoomDocumentTemplate;
use App\Models\RoomFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RoomManagement\Concerns\InteractsWithRoomManagement;
use Tests\TestCase;

class MahasiswaRoomCatalogTest extends TestCase
{
    use InteractsWithRoomManagement;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpRoomFixtures();
    }

    public function test_room_list_keeps_legacy_fields_and_gains_catalog_hints(): void
    {
        $this->actingAsMahasiswa();

        $response = $this->getJson('/api/mahasiswa/peminjaman-ruangan/rooms')->assertOk();
        $room = collect($response->json('data'))->firstWhere('id', $this->classroom->id);

        // Legacy contract intact (pre-CP3 FE keeps working).
        foreach (['id', 'code', 'name', 'type', 'capacity', 'location', 'description', 'is_active', 'owning_laboratory'] as $legacy) {
            $this->assertArrayHasKey($legacy, $room);
        }

        // New additive hints.
        $this->assertNull($room['cover_photo']);
        $this->assertSame(0, $room['facilities_summary']['count']);
        $this->assertFalse($room['has_active_template']);
        $this->assertArrayHasKey('rules', $room);
    }

    public function test_room_detail_shows_photos_facilities_rules_and_template_block(): void
    {
        // Manager prepares the room.
        $this->actingAsSuperAdmin();
        $this->classroom->update(['rules' => 'Dilarang makan di dalam ruangan.']);
        $this->postJson(
            "/api/room-management/rooms/{$this->classroom->id}/photos",
            ['photo' => UploadedFile::fake()->image('sampul.jpg', 800, 600)],
        )->assertCreated();

        $type = FacilityType::create(['name' => 'Proyektor', 'slug' => 'proyektor', 'is_predefined' => true]);
        RoomFacility::create([
            'room_id' => $this->classroom->id,
            'facility_type_id' => $type->id,
            'quantity' => 1,
            'condition' => 'baik',
        ]);

        $this->postJson("/api/room-management/rooms/{$this->classroom->id}/templates", [
            'template' => UploadedFile::fake()->createWithContent('template.pdf', "%PDF-1.4\n%%EOF\n"),
        ])->assertCreated();

        // Mahasiswa sees it all — via endpoint references, never paths.
        $this->actingAsMahasiswa();
        $response = $this->getJson("/api/mahasiswa/peminjaman-ruangan/rooms/{$this->classroom->id}")->assertOk();

        $this->assertSame('Dilarang makan di dalam ruangan.', $response->json('data.rules'));
        $this->assertCount(1, $response->json('data.photos'));
        $this->assertTrue($response->json('data.photos.0.is_cover'));
        $this->assertSame('Proyektor', $response->json('data.facilities.0.name'));
        $this->assertSame(1, $response->json('data.template.version'));
        $this->assertStringContainsString('/template', $response->json('data.template.download_url'));

        $raw = json_encode($response->json());
        foreach (['storage_disk', 'thumb_path', 'display_path', '"path"', 'room-photos/', 'room-templates/'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_inactive_room_is_hidden_from_catalog_detail_and_template(): void
    {
        $this->classroom->update(['is_active' => false]);

        $this->actingAsMahasiswa();
        $this->getJson("/api/mahasiswa/peminjaman-ruangan/rooms/{$this->classroom->id}")->assertNotFound();
        $this->getJson("/api/mahasiswa/peminjaman-ruangan/rooms/{$this->classroom->id}/template")->assertNotFound();

        $list = $this->getJson('/api/mahasiswa/peminjaman-ruangan/rooms')->assertOk()->json('data');
        $this->assertNull(collect($list)->firstWhere('id', $this->classroom->id));
    }

    public function test_template_download_resolves_lab_override_and_friendly_404(): void
    {
        $this->actingAsSuperAdmin();

        // Category-wide laboratory template + a lab-A override.
        RoomDocumentTemplate::create([
            'scope' => RoomDocumentTemplate::SCOPE_LABORATORY,
            'laboratory_id' => null,
            'storage_disk' => 'local',
            'path' => 'room-templates/laboratory/global/global.pdf',
            'original_name' => 'global.pdf',
            'mime' => RoomDocumentTemplate::MIME_PDF,
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', 'g'),
            'version' => 1,
            'is_active' => true,
        ]);
        Storage::disk('local')->put('room-templates/laboratory/global/global.pdf', '%PDF-1.4 global');

        $this->postJson("/api/room-management/rooms/{$this->labARoom->id}/templates", [
            'template' => UploadedFile::fake()->createWithContent('khusus-rpl.pdf', "%PDF-1.4 lab\n%%EOF\n"),
        ])->assertCreated();

        $this->actingAsMahasiswa();

        // Lab A room downloads its lab-specific template (v1 of the override).
        $labDownload = $this->get("/api/mahasiswa/peminjaman-ruangan/rooms/{$this->labARoom->id}/template")->assertOk();
        $this->assertStringContainsString(
            "template_peminjaman_laboratory_lab{$this->labA->id}_v1.pdf",
            (string) $labDownload->headers->get('Content-Disposition'),
        );

        // Lab B room falls back to the category-wide template.
        $this->get("/api/mahasiswa/peminjaman-ruangan/rooms/{$this->labBRoom->id}/template")->assertOk();

        // No active template anywhere → friendly Indonesian 404.
        $this->getJson("/api/mahasiswa/peminjaman-ruangan/rooms/{$this->classroom->id}/template")
            ->assertNotFound()
            ->assertJsonPath('message', 'Template peminjaman belum tersedia untuk ruangan ini. Silakan hubungi pengelola ruangan.');
    }

    public function test_booking_form_regression_room_payload_still_served(): void
    {
        // The booking flow reads the same list endpoint; ensure the response
        // shape it relies on (roomPayload keys) is untouched.
        $this->actingAsMahasiswa();
        $room = collect($this->getJson('/api/mahasiswa/peminjaman-ruangan/rooms')->json('data'))
            ->firstWhere('id', $this->labARoom->id);

        $this->assertSame($this->labARoom->code, $room['code']);
        $this->assertSame('laboratory', $room['type']);
        $this->assertSame($this->labA->code, $room['owning_laboratory']['code']);
    }
}
