<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Services\MahasiswaProfileDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAssetContractTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_mahasiswa_profile_uploads_student_assets_and_normalized_response_exposes_them(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $response = $this->actingAs($student, 'sanctum')
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('pas-foto.png', 600, 800)->size(64),
                'tanda_tangan' => UploadedFile::fake()->image('tanda-tangan.png', 10, 10)->size(64),
            ])
            ->assertOk();

        $photoPath = $response->json('profile.pas_foto_path');
        $signaturePath = $response->json('profile.tanda_tangan_path');

        $this->assertIsString($photoPath);
        $this->assertIsString($signaturePath);
        $this->assertStringStartsWith('/storage/profiles/fotos/', $photoPath);
        $this->assertStringStartsWith('/storage/profiles/signatures/', $signaturePath);

        Storage::disk('public')->assertExists($this->publicDiskPath($photoPath));
        Storage::disk('public')->assertExists($this->publicDiskPath($signaturePath));

        $this->actingAs($student->fresh(), 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('normalized.pas_foto_path', $photoPath)
            ->assertJsonPath('normalized.tanda_tangan_path', $signaturePath)
            ->assertJsonPath('student.profile.pas_foto_path', $photoPath)
            ->assertJsonPath('student.profile.tanda_tangan_path', $signaturePath);
    }

    public function test_replacing_mahasiswa_pas_foto_deletes_old_file_after_successful_save(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();
        Storage::disk('public')->put('profiles/fotos/old-pas-foto.jpg', 'old-photo');
        $profile->forceFill([
            'pas_foto_path' => Storage::url('profiles/fotos/old-pas-foto.jpg'),
        ])->save();

        $response = $this->actingAs($student, 'sanctum')
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('new-pas-foto.png', 600, 800)->size(64),
            ])
            ->assertOk();

        $newPath = $response->json('profile.pas_foto_path');
        $this->assertStringStartsWith('/storage/profiles/fotos/', $newPath);
        $this->assertSame($newPath, $profile->fresh()->pas_foto_path);
        Storage::disk('public')->assertMissing('profiles/fotos/old-pas-foto.jpg');
        Storage::disk('public')->assertExists($this->publicDiskPath($newPath));
    }

    public function test_replacing_mahasiswa_tanda_tangan_deletes_old_file_after_successful_save(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();
        Storage::disk('public')->put('profiles/signatures/old-ttd.png', 'old-signature');
        $profile->forceFill([
            'tanda_tangan_path' => Storage::url('profiles/signatures/old-ttd.png'),
        ])->save();

        $response = $this->actingAs($student, 'sanctum')
            ->post('/api/profile', [
                'tanda_tangan' => UploadedFile::fake()->image('new-ttd.png', 200, 100)->size(64),
            ])
            ->assertOk();

        $newPath = $response->json('profile.tanda_tangan_path');
        $this->assertStringStartsWith('/storage/profiles/signatures/', $newPath);
        $this->assertSame($newPath, $profile->fresh()->tanda_tangan_path);
        Storage::disk('public')->assertMissing('profiles/signatures/old-ttd.png');
        Storage::disk('public')->assertExists($this->publicDiskPath($newPath));
    }

    public function test_old_mahasiswa_pas_foto_is_not_deleted_when_new_upload_validation_fails(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();
        Storage::disk('public')->put('profiles/fotos/old-pas-foto.jpg', 'old-photo');
        $oldPath = Storage::url('profiles/fotos/old-pas-foto.jpg');
        $profile->forceFill(['pas_foto_path' => $oldPath])->save();

        $this->actingAs($student, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('too-large.jpg', 600, 800)->size(5121),
            ])
            ->assertUnprocessable();

        $this->assertSame($oldPath, $profile->fresh()->pas_foto_path);
        Storage::disk('public')->assertExists('profiles/fotos/old-pas-foto.jpg');
    }

    public function test_old_mahasiswa_tanda_tangan_is_not_deleted_when_new_upload_validation_fails(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();
        Storage::disk('public')->put('profiles/signatures/old-ttd.png', 'old-signature');
        $oldPath = Storage::url('profiles/signatures/old-ttd.png');
        $profile->forceFill(['tanda_tangan_path' => $oldPath])->save();

        $this->actingAs($student, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/profile', [
                'tanda_tangan' => UploadedFile::fake()->image('too-large.png', 200, 100)->size(2049),
            ])
            ->assertUnprocessable();

        $this->assertSame($oldPath, $profile->fresh()->tanda_tangan_path);
        Storage::disk('public')->assertExists('profiles/signatures/old-ttd.png');
    }

    public function test_replacement_cleanup_ignores_generated_document_and_global_paraf_paths(): void
    {
        config(['surat.global_paraf_path' => '/storage/profiles/signatures/global-paraf.png']);
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();
        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF');
        Storage::disk('public')->put('profiles/signatures/global-paraf.png', 'global-paraf');
        $profile->forceFill([
            'pas_foto_path' => Storage::url('surat-pengantar-magang/generated/final.pdf'),
            'tanda_tangan_path' => Storage::url('profiles/signatures/global-paraf.png'),
        ])->save();

        $this->actingAs($student, 'sanctum')
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('new-pas-foto.png', 600, 800)->size(64),
                'tanda_tangan' => UploadedFile::fake()->image('new-ttd.png', 200, 100)->size(64),
            ])
            ->assertOk();

        Storage::disk('public')->assertExists('surat-pengantar-magang/generated/final.pdf');
        Storage::disk('public')->assertExists('profiles/signatures/global-paraf.png');
    }

    public function test_replacement_cleanup_ignores_external_url_and_traversal_old_paths(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();
        Storage::disk('public')->put('profiles/fotos/external.jpg', 'external-local-copy');
        Storage::disk('public')->put('profiles/shared-signature.png', 'shared-signature');
        $profile->forceFill([
            'pas_foto_path' => 'https://cdn.example.test/storage/profiles/fotos/external.jpg',
            'tanda_tangan_path' => '/storage/profiles/signatures/%2e%2e/shared-signature.png',
        ])->save();

        $this->actingAs($student, 'sanctum')
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('new-pas-foto.png', 600, 800)->size(64),
                'tanda_tangan' => UploadedFile::fake()->image('new-ttd.png', 200, 100)->size(64),
            ])
            ->assertOk();

        Storage::disk('public')->assertExists('profiles/fotos/external.jpg');
        Storage::disk('public')->assertExists('profiles/shared-signature.png');
    }

    public function test_google_avatar_and_account_photo_do_not_become_official_pas_foto(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa([
            'avatar_url' => 'https://example.test/google-avatar.png',
            'photo_path' => '/storage/profiles/fotos/account-photo.png',
        ]);

        Storage::disk('public')->put('profiles/fotos/account-photo.png', 'account-photo');

        $normalized = app(MahasiswaProfileDataService::class)->forUser($student->fresh());
        $this->assertNull($normalized['pas_foto_path']);
        $this->assertArrayNotHasKey('avatar_url', $normalized);
        $this->assertArrayNotHasKey('photo_path', $normalized);

        $profileResponse = $this->actingAs($student->fresh(), 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('normalized.pas_foto_path', null)
            ->assertJsonPath('student.profile.pas_foto_path', null);

        $this->assertArrayNotHasKey('avatar_url', $profileResponse->json('normalized'));
        $this->assertArrayNotHasKey('photo_path', $profileResponse->json('normalized'));
    }

    public function test_beasiswa_step_two_does_not_write_legacy_signature_payload(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();
        $this->scholarshipApplication($student, [
            'mahasiswa_profile_id' => $profile->id,
            'status' => ScholarshipApplication::STATUS_DRAFT,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/step-2', [
                'pob' => 'Sleman',
                'dob' => '2004-01-15',
                'gender' => 'Laki-laki',
                'origin_address' => 'Sleman',
                'jogja_address' => 'Yogyakarta',
                'signature' => 'data:image/png;base64,' . base64_encode('legacy-signature'),
                'father_name' => 'Ayah Test',
                'father_job' => 'Pegawai',
                'father_income' => 1000000,
                'father_status' => 'Hidup',
                'mother_name' => 'Ibu Test',
                'mother_job' => 'Pegawai',
                'mother_income' => 1000000,
                'mother_status' => 'Hidup',
                'siblings' => [],
            ])
            ->assertOk();

        $this->assertNull($profile->fresh()->tanda_tangan_path);
        $this->assertSame([], Storage::disk('public')->allFiles('signatures'));
    }

    public function test_pas_foto_accepts_file_up_to_5mb(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('foto-large.jpg', 600, 800)->size(5120),
            ])
            ->assertOk();
    }

    public function test_pas_foto_rejects_file_over_5mb(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('foto-too-large.jpg', 100, 100)->size(5121),
            ])
            ->assertStatus(422);
    }

    public function test_tanda_tangan_rejects_file_over_2mb(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/profile', [
                'tanda_tangan' => UploadedFile::fake()->image('ttd-too-large.png', 100, 100)->size(2049),
            ])
            ->assertStatus(422);
    }

    private function publicDiskPath(string $path): string
    {
        $path = ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/');

        return str_starts_with($path, 'storage/')
            ? substr($path, strlen('storage/'))
            : $path;
    }
}
