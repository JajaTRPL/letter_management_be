<?php

namespace Tests\Feature\Workflow;

use App\Models\MahasiswaProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProfileAssetReplacementSmokeTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_mahasiswa_pas_foto_replacement_deletes_first_upload_and_keeps_second(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();

        $first = $this->uploadProfileAsset(
            $student,
            'pas_foto',
            UploadedFile::fake()->image('pas-foto-a.png', 600, 800)->size(64),
            'profile.pas_foto_path'
        );
        $this->assertSame($first, $profile->fresh()->pas_foto_path);
        Storage::disk('public')->assertExists($this->publicDiskPath($first));

        $second = $this->uploadProfileAsset(
            $student,
            'pas_foto',
            UploadedFile::fake()->image('pas-foto-b.png', 600, 800)->size(64),
            'profile.pas_foto_path'
        );

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $profile->fresh()->pas_foto_path);
        Storage::disk('public')->assertMissing($this->publicDiskPath($first));
        Storage::disk('public')->assertExists($this->publicDiskPath($second));
    }

    public function test_mahasiswa_tanda_tangan_replacement_deletes_first_upload_and_keeps_second(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();

        $first = $this->uploadProfileAsset(
            $student,
            'tanda_tangan',
            UploadedFile::fake()->image('ttd-a.png', 240, 120)->size(64),
            'profile.tanda_tangan_path'
        );
        $this->assertSame($first, $profile->fresh()->tanda_tangan_path);
        Storage::disk('public')->assertExists($this->publicDiskPath($first));

        $second = $this->uploadProfileAsset(
            $student,
            'tanda_tangan',
            UploadedFile::fake()->image('ttd-b.png', 240, 120)->size(64),
            'profile.tanda_tangan_path'
        );

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $profile->fresh()->tanda_tangan_path);
        Storage::disk('public')->assertMissing($this->publicDiskPath($first));
        Storage::disk('public')->assertExists($this->publicDiskPath($second));
    }

    public function test_tendik_and_akademik_profile_photo_and_signature_replacements_delete_first_uploads(): void
    {
        Storage::fake('public');

        foreach ([
            'tendik' => $this->tendikPersuratan(),
            'akademik' => $this->akademik('kaprodi'),
        ] as $label => $user) {
            $firstPhoto = $this->uploadProfileAsset(
                $user,
                'pas_foto',
                UploadedFile::fake()->image("{$label}-photo-a.png", 240, 240)->size(64),
                'profile.pas_foto_path'
            );
            $secondPhoto = $this->uploadProfileAsset(
                $user,
                'pas_foto',
                UploadedFile::fake()->image("{$label}-photo-b.png", 240, 240)->size(64),
                'profile.pas_foto_path'
            );

            $this->assertNotSame($firstPhoto, $secondPhoto);
            $this->assertSame($secondPhoto, $user->fresh()->photo_path);
            Storage::disk('public')->assertMissing($this->publicDiskPath($firstPhoto));
            Storage::disk('public')->assertExists($this->publicDiskPath($secondPhoto));

            $firstSignature = $this->uploadProfileAsset(
                $user,
                'tanda_tangan',
                UploadedFile::fake()->image("{$label}-signature-a.png", 240, 120)->size(64),
                'profile.tanda_tangan_path'
            );
            $secondSignature = $this->uploadProfileAsset(
                $user,
                'tanda_tangan',
                UploadedFile::fake()->image("{$label}-signature-b.png", 240, 120)->size(64),
                'profile.tanda_tangan_path'
            );

            $this->assertNotSame($firstSignature, $secondSignature);
            $this->assertSame($secondSignature, $user->fresh()->signature_path);
            Storage::disk('public')->assertMissing($this->publicDiskPath($firstSignature));
            Storage::disk('public')->assertExists($this->publicDiskPath($secondSignature));
        }
    }

    public function test_replacement_does_not_delete_shared_profile_asset_references(): void
    {
        Storage::fake('public');

        $sharedStaffPhoto = 'profiles/fotos/shared-staff-photo.jpg';
        Storage::disk('public')->put($sharedStaffPhoto, 'shared-staff-photo');
        $owner = $this->tendikPersuratan([], ['photo_path' => Storage::url($sharedStaffPhoto)]);
        $actor = $this->tendikPersuratan([], ['photo_path' => Storage::url($sharedStaffPhoto)]);

        $this->uploadProfileAsset(
            $actor,
            'pas_foto',
            UploadedFile::fake()->image('actor-new-photo.png', 240, 240)->size(64),
            'profile.pas_foto_path'
        );

        Storage::disk('public')->assertExists($sharedStaffPhoto);
        $this->assertSame(Storage::url($sharedStaffPhoto), $owner->fresh()->photo_path);

        $sharedStudentSignature = 'profiles/signatures/shared-student-signature.png';
        Storage::disk('public')->put($sharedStudentSignature, 'shared-student-signature');
        [, $ownerProfile] = $this->completeMahasiswa([], [
            'tanda_tangan_path' => Storage::url($sharedStudentSignature),
        ]);
        [$student, $studentProfile] = $this->completeMahasiswa([], [
            'tanda_tangan_path' => Storage::url($sharedStudentSignature),
        ]);

        $this->uploadProfileAsset(
            $student,
            'tanda_tangan',
            UploadedFile::fake()->image('student-new-signature.png', 240, 120)->size(64),
            'profile.tanda_tangan_path'
        );

        Storage::disk('public')->assertExists($sharedStudentSignature);
        $this->assertSame(Storage::url($sharedStudentSignature), $ownerProfile->fresh()->tanda_tangan_path);
        $this->assertNotSame(Storage::url($sharedStudentSignature), $studentProfile->fresh()->tanda_tangan_path);
    }

    public function test_replacement_does_not_delete_generated_global_external_or_traversal_paths(): void
    {
        config(['surat.global_paraf_path' => '/storage/profiles/signatures/global-paraf.png']);
        Storage::fake('public');

        foreach ([
            'surat-pengantar-magang/generated/smoke.pdf' => '%PDF',
            'profiles/signatures/global-paraf.png' => 'global-paraf',
            'profiles/fotos/external-shadow.jpg' => 'external-shadow',
            'profiles/raw-traversal-shadow.png' => 'raw-traversal-shadow',
            'profiles/encoded-traversal-shadow.png' => 'encoded-traversal-shadow',
        ] as $path => $content) {
            Storage::disk('public')->put($path, $content);
        }

        $this->replaceStudentOldPathAndAssertKept(
            'pas_foto_path',
            Storage::url('surat-pengantar-magang/generated/smoke.pdf'),
            'surat-pengantar-magang/generated/smoke.pdf',
            'pas_foto'
        );

        $this->replaceStudentOldPathAndAssertKept(
            'tanda_tangan_path',
            Storage::url('profiles/signatures/global-paraf.png'),
            'profiles/signatures/global-paraf.png',
            'tanda_tangan'
        );

        $this->replaceStudentOldPathAndAssertKept(
            'pas_foto_path',
            'https://cdn.example.test/storage/profiles/fotos/external-shadow.jpg',
            'profiles/fotos/external-shadow.jpg',
            'pas_foto'
        );

        $this->replaceStudentOldPathAndAssertKept(
            'tanda_tangan_path',
            '/storage/profiles/signatures/../raw-traversal-shadow.png',
            'profiles/raw-traversal-shadow.png',
            'tanda_tangan'
        );

        $this->replaceStudentOldPathAndAssertKept(
            'tanda_tangan_path',
            '/storage/profiles/signatures/%2e%2e/encoded-traversal-shadow.png',
            'profiles/encoded-traversal-shadow.png',
            'tanda_tangan'
        );
    }

    public function test_invalid_upload_keeps_old_asset_and_does_not_write_new_path(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();
        $oldPhoto = 'profiles/fotos/student-old.jpg';
        Storage::disk('public')->put($oldPhoto, 'old-student-photo');
        $profile->forceFill(['pas_foto_path' => Storage::url($oldPhoto)])->save();

        $this->actingAs($student, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('too-large.jpg', 600, 800)->size(5121),
            ])
            ->assertUnprocessable();

        $this->assertSame(Storage::url($oldPhoto), $profile->fresh()->pas_foto_path);
        Storage::disk('public')->assertExists($oldPhoto);
        $this->assertSame([$oldPhoto], Storage::disk('public')->allFiles('profiles/fotos'));

        $staff = $this->tendikPersuratan();
        $oldSignature = 'profiles/signatures/staff-old.png';
        Storage::disk('public')->put($oldSignature, 'old-staff-signature');
        $staff->forceFill(['signature_path' => Storage::url($oldSignature)])->save();

        $this->actingAs($staff, 'sanctum')
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/profile', [
                'tanda_tangan' => UploadedFile::fake()->image('too-large-signature.png', 240, 120)->size(2049),
            ])
            ->assertUnprocessable();

        $this->assertSame(Storage::url($oldSignature), $staff->fresh()->signature_path);
        Storage::disk('public')->assertExists($oldSignature);
    }

    public function test_new_upload_is_cleaned_up_if_database_save_fails_after_storage(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();
        $oldPhoto = 'profiles/fotos/student-old-before-failure.jpg';
        Storage::disk('public')->put($oldPhoto, 'old-student-photo');
        $profile->forceFill(['pas_foto_path' => Storage::url($oldPhoto)])->save();

        $dispatcher = MahasiswaProfile::getEventDispatcher();
        $isolatedDispatcher = clone $dispatcher;

        try {
            MahasiswaProfile::setEventDispatcher($isolatedDispatcher);
            MahasiswaProfile::saving(function (MahasiswaProfile $savingProfile): void {
                if ($savingProfile->isDirty('pas_foto_path')) {
                    throw new RuntimeException('Forced profile save failure after upload.');
                }
            });

            $this->actingAs($student, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->post('/api/profile', [
                    'pas_foto' => UploadedFile::fake()->image('new-before-failure.png', 600, 800)->size(64),
                ])
                ->assertStatus(500);
        } finally {
            MahasiswaProfile::setEventDispatcher($dispatcher);
        }

        $this->assertSame(Storage::url($oldPhoto), $profile->fresh()->pas_foto_path);
        $this->assertSame([$oldPhoto], Storage::disk('public')->allFiles('profiles/fotos'));
    }

    public function test_protected_storage_smoke_keeps_signatures_private_and_blocks_public_paths(): void
    {
        Storage::fake('public');

        [$student, $profile] = $this->completeMahasiswa();
        $studentSignature = 'profiles/signatures/student-secret.png';
        Storage::disk('public')->put($studentSignature, 'student-secret-payload');
        $profile->forceFill(['tanda_tangan_path' => Storage::url($studentSignature)])->save();
        [$foreignStudent] = $this->completeMahasiswa();

        $unauthenticatedResponse = $this->getJson('/api/storage/' . $studentSignature)
            ->assertUnauthorized();
        $this->assertStringNotContainsString('student-secret-payload', $unauthenticatedResponse->getContent());

        $publicResponse = $this->get('/storage/' . $studentSignature)
            ->assertForbidden();
        $this->assertStringNotContainsString('student-secret-payload', $publicResponse->getContent());

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/' . $studentSignature)
            ->assertOk();

        $foreignStudentResponse = $this->actingAs($foreignStudent, 'sanctum')
            ->get('/api/storage/' . $studentSignature)
            ->assertForbidden();
        $this->assertStringNotContainsString('student-secret-payload', $foreignStudentResponse->getContent());

        $staff = $this->tendikPersuratan();
        $otherStaff = $this->tendikPersuratan();
        $staffSignature = 'profiles/signatures/staff-secret.png';
        Storage::disk('public')->put($staffSignature, 'staff-secret-payload');
        $staff->forceFill(['signature_path' => Storage::url($staffSignature)])->save();

        $this->actingAs($staff, 'sanctum')
            ->get('/api/storage/' . $staffSignature)
            ->assertOk();

        $otherStaffResponse = $this->actingAs($otherStaff, 'sanctum')
            ->get('/api/storage/' . $staffSignature)
            ->assertForbidden();
        $this->assertStringNotContainsString('staff-secret-payload', $otherStaffResponse->getContent());

        $generatedPath = 'surat-pengantar-magang/generated/secret.pdf';
        Storage::disk('public')->put($generatedPath, '%PDF-secret-payload');
        $generatedResponse = $this->actingAs($student, 'sanctum')
            ->get('/api/storage/' . $generatedPath)
            ->assertForbidden();
        $this->assertStringNotContainsString('%PDF-secret-payload', $generatedResponse->getContent());
    }

    private function uploadProfileAsset(User $user, string $field, UploadedFile $file, string $responsePath): string
    {
        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/profile', [
                $field => $file,
            ])
            ->assertOk();

        $path = $response->json($responsePath);
        $this->assertIsString($path);

        return $path;
    }

    private function replaceStudentOldPathAndAssertKept(
        string $profileField,
        string $oldValue,
        string $expectedKeptPath,
        string $uploadField
    ): void {
        [$student, $profile] = $this->completeMahasiswa();
        $profile->forceFill([$profileField => $oldValue])->save();

        $file = $uploadField === 'pas_foto'
            ? UploadedFile::fake()->image('replacement-photo.png', 600, 800)->size(64)
            : UploadedFile::fake()->image('replacement-signature.png', 240, 120)->size(64);

        $this->uploadProfileAsset(
            $student,
            $uploadField,
            $file,
            $uploadField === 'pas_foto' ? 'profile.pas_foto_path' : 'profile.tanda_tangan_path'
        );

        Storage::disk('public')->assertExists($expectedKeptPath);
    }

    private function publicDiskPath(string $path): string
    {
        $path = ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/');

        return str_starts_with($path, 'storage/')
            ? substr($path, strlen('storage/'))
            : $path;
    }
}
