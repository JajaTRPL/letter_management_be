<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratTugasApplication;
use App\Models\User;
use App\Services\AcademicSignatoryService;
use App\Services\SuratKeteranganAktifPreviewGenerationService;
use App\Services\SuratTugasPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Kadep can keep a second signature image (TTD + cap departemen) without any
 * users column, and the department approver chooses per approval which one is
 * embedded in the final document via the optional `signature_variant` field.
 */
class KadepStampedSignatureTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_kadep_uploads_stamped_signature_without_touching_regular_signature(): void
    {
        $kadep = $this->akademik('kadep', ['signature_path' => '/storage/profiles/signatures/regular.png']);
        Storage::disk('public')->put('profiles/signatures/regular.png', 'regular');

        $response = $this->actingAs($kadep, 'sanctum')
            ->post('/api/profile', [
                '_method' => 'PUT',
                'name' => $kadep->name,
                'tanda_tangan_cap' => UploadedFile::fake()->image('cap.png', 200, 100)->size(64),
            ])
            ->assertOk()
            ->assertJsonPath('profile.tanda_tangan_path', '/storage/profiles/signatures/regular.png')
            ->assertJsonPath('profile.can_upload_tanda_tangan_cap', true);

        $stampedPath = $response->json('profile.tanda_tangan_cap_path');
        $this->assertIsString($stampedPath);
        $this->assertStringStartsWith("/storage/profiles/signatures-stamped/{$kadep->id}/", $stampedPath);
        Storage::disk('public')->assertExists(ltrim(substr($stampedPath, strlen('/storage/')), '/'));
        Storage::disk('public')->assertExists('profiles/signatures/regular.png');
        $this->assertSame('/storage/profiles/signatures/regular.png', $kadep->fresh()->signature_path);

        $this->actingAs($kadep->fresh(), 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('profile.tanda_tangan_cap_path', $stampedPath)
            ->assertJsonPath('profile.tanda_tangan_path', '/storage/profiles/signatures/regular.png');
    }

    public function test_replacing_stamped_signature_keeps_only_the_new_file(): void
    {
        $kadep = $this->akademik('kadep');

        $first = $this->actingAs($kadep, 'sanctum')
            ->post('/api/profile', [
                '_method' => 'PUT',
                'tanda_tangan_cap' => UploadedFile::fake()->image('cap-1.png', 200, 100)->size(64),
            ])
            ->assertOk()
            ->json('profile.tanda_tangan_cap_path');

        $second = $this->actingAs($kadep->fresh(), 'sanctum')
            ->post('/api/profile', [
                '_method' => 'PUT',
                'tanda_tangan_cap' => UploadedFile::fake()->image('cap-2.png', 200, 100)->size(64),
            ])
            ->assertOk()
            ->json('profile.tanda_tangan_cap_path');

        $this->assertNotSame($first, $second);
        $files = Storage::disk('public')->files("profiles/signatures-stamped/{$kadep->id}");
        $this->assertCount(1, $files);
        $this->assertSame($second, Storage::url($files[0]));
    }

    public function test_only_kadep_can_upload_stamped_signature(): void
    {
        foreach (['kaprodi', 'sekdep'] as $subRole) {
            $user = $this->akademik($subRole);

            $this->actingAs($user, 'sanctum')
                ->post('/api/profile', [
                    '_method' => 'PUT',
                    'tanda_tangan_cap' => UploadedFile::fake()->image('cap.png', 200, 100)->size(64),
                ], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['tanda_tangan_cap']);

            $this->assertSame([], Storage::disk('public')->allFiles("profiles/signatures-stamped/{$user->id}"));

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/profile')
                ->assertOk()
                ->assertJsonPath('profile.tanda_tangan_cap_path', null)
                ->assertJsonPath('profile.can_upload_tanda_tangan_cap', false);
        }
    }

    public function test_stamped_signature_file_is_owner_readable_only(): void
    {
        $kadep = $this->akademik('kadep');
        $otherAkademik = $this->akademik('sekdep');
        Storage::disk('public')->put("profiles/signatures-stamped/{$kadep->id}/abc.png", 'png');

        $this->actingAs($kadep, 'sanctum')
            ->get("/api/storage/profiles/signatures-stamped/{$kadep->id}/abc.png")
            ->assertOk();

        $this->actingAs($otherAkademik, 'sanctum')
            ->get("/api/storage/profiles/signatures-stamped/{$kadep->id}/abc.png")
            ->assertForbidden();
    }

    public function test_surat_tugas_kadep_approve_with_stamped_variant_renders_stamped_signature(): void
    {
        [$application, $kadep] = $this->suratTugasAwaitingKadep();
        Storage::disk('public')->put("profiles/signatures-stamped/{$kadep->id}/cap.png", 'stamped');

        $captured = $this->captureSuratTugasOverrides();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve", [
                'signature_variant' => 'stamped',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW);

        $renderKadep = $captured->overrides['official_kadep'];
        $this->assertInstanceOf(User::class, $renderKadep);
        $this->assertSame($kadep->id, $renderKadep->id);
        $this->assertSame("/storage/profiles/signatures-stamped/{$kadep->id}/cap.png", $renderKadep->signature_path);
        // The stored regular signature is never overwritten by the render copy.
        $this->assertSame('/storage/profiles/signatures/kadep-regular.png', $kadep->fresh()->signature_path);
    }

    public function test_surat_tugas_kadep_approve_without_variant_keeps_regular_signature(): void
    {
        [$application, $kadep] = $this->suratTugasAwaitingKadep();
        Storage::disk('public')->put("profiles/signatures-stamped/{$kadep->id}/cap.png", 'stamped');

        $captured = $this->captureSuratTugasOverrides();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve")
            ->assertOk();

        $this->assertSame('/storage/profiles/signatures/kadep-regular.png', $captured->overrides['official_kadep']->signature_path);
    }

    public function test_stamped_variant_without_uploaded_stamp_is_rejected_without_side_effects(): void
    {
        [$application, $kadep] = $this->suratTugasAwaitingKadep();

        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve", [
                'signature_variant' => 'stamped',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'missing_stamped_signature');

        $fresh = $application->fresh();
        $this->assertSame(SuratTugasApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertNull($fresh->kadep_approved_by);
    }

    public function test_invalid_signature_variant_is_rejected(): void
    {
        [$application, $kadep] = $this->suratTugasAwaitingKadep();

        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve", [
                'signature_variant' => 'something-else',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signature_variant']);

        $this->assertSame(SuratTugasApplication::STATUS_APPROVED_KAPRODI, $application->fresh()->status);
    }

    public function test_sekdep_approval_can_choose_the_official_kadeps_stamped_signature(): void
    {
        [$application, $kadep] = $this->suratTugasAwaitingKadep();
        $sekdep = $this->akademik('sekdep');
        Storage::disk('public')->put("profiles/signatures-stamped/{$kadep->id}/cap.png", 'stamped');

        $captured = $this->captureSuratTugasOverrides();

        $this->actingAs($sekdep, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve", [
                'signature_variant' => 'stamped',
            ])
            ->assertOk();

        $this->assertSame($kadep->id, $captured->overrides['official_kadep']->id);
        $this->assertSame("/storage/profiles/signatures-stamped/{$kadep->id}/cap.png", $captured->overrides['official_kadep']->signature_path);
    }

    public function test_surat_keterangan_aktif_stamped_variant_adds_official_kadep_override(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep', ['signature_path' => '/storage/profiles/signatures/kadep-regular.png']);
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-CAP-001',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);
        Storage::disk('public')->put("profiles/signatures-stamped/{$kadep->id}/cap.png", 'stamped');

        $captured = new \stdClass();
        $captured->overrides = [];
        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function ($app, string $phase, array $overrides = []) use ($captured) {
                $captured->overrides = $overrides;

                return LetterDocumentArtifact::make([
                    'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
                    'application_id' => $app->getKey(),
                    'phase' => $phase,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve", [
                'signature_variant' => 'stamped',
            ])
            ->assertOk();

        $this->assertSame("/storage/profiles/signatures-stamped/{$kadep->id}/cap.png", $captured->overrides['official_kadep']->signature_path);
    }

    public function test_signatory_service_returns_unchanged_kadep_for_default_variant(): void
    {
        $kadep = $this->akademik('kadep', ['signature_path' => '/storage/profiles/signatures/kadep-regular.png']);
        Storage::disk('public')->put("profiles/signatures-stamped/{$kadep->id}/cap.png", 'stamped');
        $service = app(AcademicSignatoryService::class);

        $this->assertSame($kadep, $service->kadepForSignatureVariant($kadep, AcademicSignatoryService::SIGNATURE_VARIANT_DEFAULT));
        $stamped = $service->kadepForSignatureVariant($kadep, AcademicSignatoryService::SIGNATURE_VARIANT_STAMPED);
        $this->assertNotSame($kadep, $stamped);
        $this->assertSame('/storage/profiles/signatures/kadep-regular.png', $kadep->signature_path);
        $this->assertSame("/storage/profiles/signatures-stamped/{$kadep->id}/cap.png", $stamped->signature_path);
        $this->assertSame('/storage/profiles/signatures/kadep-regular.png', $kadep->fresh()->signature_path);
        $this->assertNull($service->kadepForSignatureVariant(
            $this->akademik('kadep'),
            AcademicSignatoryService::SIGNATURE_VARIANT_STAMPED,
        ));
    }

    /**
     * @return array{0: SuratTugasApplication, 1: User}
     */
    private function suratTugasAwaitingKadep(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-05-25 09:10:20'));
        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep', ['signature_path' => '/storage/profiles/signatures/kadep-regular.png']);
        Storage::disk('public')->put('profiles/signatures/kadep-regular.png', 'regular');
        [$student] = $this->completeMahasiswa();
        $application = $this->suratTugasApplication($student, [
            'status' => SuratTugasApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat_tugas' => 'ST/CAP/001',
            'tendik_approved_at' => Carbon::parse('2026-05-24 08:00:00'),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => Carbon::parse('2026-05-24 09:00:00'),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        return [$application, $kadep];
    }

    private function captureSuratTugasOverrides(): \stdClass
    {
        $captured = new \stdClass();
        $captured->overrides = [];
        $mock = Mockery::mock(SuratTugasPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function ($application, string $phase, array $overrides = []) use ($captured) {
                $this->assertSame(LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW, $phase);
                $captured->overrides = $overrides;

                return LetterDocumentArtifact::make([
                    'letter_type' => SuratTugasApplication::LETTER_TYPE,
                    'application_id' => $application->getKey(),
                    'phase' => $phase,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(SuratTugasPreviewGenerationService::class, $mock);

        return $captured;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
