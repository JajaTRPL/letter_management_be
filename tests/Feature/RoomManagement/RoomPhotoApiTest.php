<?php

namespace Tests\Feature\RoomManagement;

use App\Models\RoomPhoto;
use App\Services\RoomMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RoomManagement\Concerns\InteractsWithRoomManagement;
use Tests\TestCase;

class RoomPhotoApiTest extends TestCase
{
    use InteractsWithRoomManagement;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpRoomFixtures();
    }

    public function test_upload_creates_private_variants_and_first_photo_becomes_cover(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson(
            "/api/room-management/rooms/{$this->classroom->id}/photos",
            ['photo' => UploadedFile::fake()->image('ruang kelas.jpg', 1200, 800)],
        )->assertCreated()
            ->assertJsonPath('data.is_cover', true)
            ->assertJsonPath('data.width', 1200);

        $json = $response->json('data');
        $this->assertStringStartsWith('/api/rooms/', $json['thumb_url']);
        $this->assertStringStartsWith('/api/rooms/', $json['display_url']);

        // Storage internals must never leak through the API.
        $raw = json_encode($response->json());
        foreach (['storage_disk', 'thumb_path', 'display_path', 'full_path', 'room-photos/'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }

        $photo = RoomPhoto::firstOrFail();
        foreach ([$photo->thumb_path, $photo->display_path, $photo->full_path] as $path) {
            $this->assertNotNull($path);
            Storage::disk('local')->assertExists($path);
        }

        $this->assertDatabaseHas('room_audit_logs', [
            'room_id' => $this->classroom->id,
            'subject_type' => 'photo',
            'action' => 'uploaded',
        ]);
    }

    public function test_cover_selection_reorder_and_delete_with_file_cleanup(): void
    {
        $this->actingAsSuperAdmin();
        $base = "/api/room-management/rooms/{$this->classroom->id}/photos";

        $first = $this->postJson($base, ['photo' => UploadedFile::fake()->image('a.jpg', 800, 600)])->json('data.id');
        $second = $this->postJson($base, ['photo' => UploadedFile::fake()->image('b.jpg', 800, 600)])->json('data.id');

        $this->assertFalse(RoomPhoto::find($second)->is_cover);

        // Set the second photo as cover.
        $this->postJson("{$base}/{$second}/cover")->assertOk()->assertJsonPath('data.is_cover', true);
        $this->assertFalse(RoomPhoto::find($first)->fresh()->is_cover);

        // Reorder; foreign/missing ids are rejected.
        $this->patchJson("{$base}/reorder", ['photo_ids' => [$second, $first]])->assertOk();
        $this->assertSame([$second, $first], $this->classroom->photos()->pluck('id')->all());
        $this->patchJson("{$base}/reorder", ['photo_ids' => [$second]])->assertUnprocessable();
        $this->patchJson("{$base}/reorder", ['photo_ids' => [$second, 999999]])->assertUnprocessable();

        // Deleting the cover removes files and promotes the next photo.
        $coverPaths = array_filter([
            RoomPhoto::find($second)->thumb_path,
            RoomPhoto::find($second)->display_path,
            RoomPhoto::find($second)->full_path,
        ]);
        $this->deleteJson("{$base}/{$second}")->assertOk();

        $this->assertDatabaseMissing('room_photos', ['id' => $second]);
        foreach ($coverPaths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertTrue(RoomPhoto::find($first)->fresh()->is_cover);
    }

    public function test_photo_cap_fake_image_and_small_image_are_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $base = "/api/room-management/rooms/{$this->classroom->id}/photos";

        // Cap: fill up to the limit directly, then the API refuses more.
        for ($i = 0; $i < RoomMediaService::MAX_PHOTOS_PER_ROOM; $i++) {
            RoomPhoto::create([
                'room_id' => $this->classroom->id,
                'storage_disk' => 'local',
                'thumb_path' => "room-photos/{$this->classroom->id}/{$i}_t.jpg",
                'display_path' => "room-photos/{$this->classroom->id}/{$i}_d.jpg",
                'original_name' => "foto{$i}.jpg",
                'mime' => 'image/jpeg',
                'size_bytes' => 100,
                'width' => 800,
                'height' => 600,
                'checksum_sha256' => hash('sha256', (string) $i),
                'sort_order' => $i,
            ]);
        }
        $this->postJson($base, ['photo' => UploadedFile::fake()->image('x.jpg', 800, 600)])
            ->assertUnprocessable();

        RoomPhoto::query()->delete();

        // Text bytes with a .jpg name are not an image.
        $this->postJson($base, [
            'photo' => UploadedFile::fake()->createWithContent('palsu.jpg', 'bukan gambar'),
        ])->assertUnprocessable();

        // Real image below the minimum dimension.
        $this->postJson($base, ['photo' => UploadedFile::fake()->image('kecil.jpg', 100, 100)])
            ->assertUnprocessable();
    }

    public function test_media_permission_matrix_for_upload(): void
    {
        $labBase = "/api/room-management/rooms/{$this->labARoom->id}/photos";
        $classBase = "/api/room-management/rooms/{$this->classroom->id}/photos";
        $image = fn () => ['photo' => UploadedFile::fake()->image('foto.jpg', 800, 600)];

        $this->actingAsSarpras();
        $this->postJson($classBase, $image())->assertCreated();
        $this->postJson($labBase, $image())->assertNotFound();

        $this->actingAsLaboran();
        $this->postJson($labBase, $image())->assertCreated();
        $this->postJson("/api/room-management/rooms/{$this->labBRoom->id}/photos", $image())->assertCreated();
        $this->postJson($classBase, $image())->assertNotFound();

        $this->actingAsKalab();
        $this->postJson($labBase, $image())->assertCreated();
        $this->postJson("/api/room-management/rooms/{$this->labBRoom->id}/photos", $image())->assertNotFound();
    }

    public function test_media_delivery_respects_room_visibility(): void
    {
        $this->actingAsSuperAdmin();
        $photoId = $this->postJson(
            "/api/room-management/rooms/{$this->classroom->id}/photos",
            ['photo' => UploadedFile::fake()->image('foto.jpg', 800, 600)],
        )->json('data.id');

        $url = "/api/rooms/{$this->classroom->id}/photos/{$photoId}/thumb";

        // Active room: any authenticated user sees the photo.
        $this->actingAsMahasiswa();
        $response = $this->get($url)->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('ETag'));

        // Unknown variant is hidden.
        $this->get("/api/rooms/{$this->classroom->id}/photos/{$photoId}/original")->assertNotFound();

        // Inactive room: invisible to mahasiswa, still visible to managers.
        $this->classroom->update(['is_active' => false]);
        $this->get($url)->assertNotFound();

        $this->actingAsSuperAdmin();
        $this->get($url)->assertOk();

        // Unauthenticated requests never reach the file.
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }
}
