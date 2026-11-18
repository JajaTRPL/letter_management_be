<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use App\Services\SuratKeteranganAktifPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * There is no fallback paraf image: an approval whose document carries the
 * Prodi paraf is refused until the Kaprodi of the student's program uploads
 * their paraf, with a clear message and no state change.
 */
class KaprodiParafRequiredForApprovalTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_sekprodi_approval_is_refused_until_kaprodi_uploads_paraf(): void
    {
        $this->akademik('kaprodi', ['signature_path' => null]);
        $sekprodi = $this->akademik('sekprodi');
        $application = $this->skaAwaiting(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK);
        $this->expectNoGeneration();

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'missing_kaprodi_paraf')
            ->assertJsonFragment(['message' => 'Paraf Kaprodi Program Studi ' . $this->defaultStudyProgram()->name . ' belum diunggah. Kaprodi perlu mengunggah paraf terlebih dahulu di menu Profil sebelum pengajuan ini dapat disetujui.']);

        $fresh = $application->fresh();
        $this->assertSame(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertNull($fresh->kaprodi_approved_by);
    }

    public function test_approval_is_refused_when_the_program_has_no_kaprodi(): void
    {
        $sekprodi = $this->akademik('sekprodi');
        $application = $this->skaAwaiting(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK);
        $this->expectNoGeneration();

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'missing_official_kaprodi');
    }

    public function test_kadep_approval_is_also_refused_while_kaprodi_paraf_is_missing(): void
    {
        $this->akademik('kaprodi', ['signature_path' => null]);
        $kadep = $this->akademik('kadep', ['signature_path' => '/storage/profiles/signatures/kadep.png']);
        Storage::disk('public')->put('profiles/signatures/kadep.png', 'kadep');
        $application = $this->skaAwaiting(SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI);
        $this->expectNoGeneration();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'missing_kaprodi_paraf');

        $this->assertSame(SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI, $application->fresh()->status);
    }

    public function test_sekprodi_approval_proceeds_once_kaprodi_has_uploaded_paraf(): void
    {
        Storage::disk('public')->put('profiles/signatures/kaprodi.png', 'paraf');
        $this->akademik('kaprodi', ['signature_path' => '/storage/profiles/signatures/kaprodi.png']);
        $sekprodi = $this->akademik('sekprodi');
        $application = $this->skaAwaiting(SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK);

        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(fn ($app, string $phase) => LetterDocumentArtifact::make([
                'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
                'application_id' => $app->getKey(),
                'phase' => $phase,
                'status' => LetterDocumentArtifact::STATUS_READY,
            ]));
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI);
    }

    private function skaAwaiting(string $status): SuratKeteranganAktifApplication
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();

        return $this->aktifApplication($student, array_merge([
            'status' => $status,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'AKT-PARAF-001',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ], $status === SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI ? [
            'kaprodi_approved_at' => now(),
        ] : []));
    }

    private function expectNoGeneration(): void
    {
        $mock = Mockery::mock(SuratKeteranganAktifPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(SuratKeteranganAktifPreviewGenerationService::class, $mock);
    }
}
