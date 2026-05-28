<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ScholarshipApplication;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApprovalTransitionTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_aktif_uses_canonical_approval_flow_through_student_completion(): void
    {
        Notification::fake();
        Storage::fake('local');
        $this->mockSkaPreviewGenerationAlwaysReady();

        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/approve", [
                'nomor_surat' => 'AKT-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->createReadyAktifMahasiswaArtifact($application->fresh());

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/generated-preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_COMPLETED,
        ]);
    }

    public function test_aktif_tendik_approval_requires_nomor_surat(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nomor_surat']);

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
            'nomor_surat' => null,
        ]);
    }

    public function test_proses_luar_negeri_uses_canonical_approval_flow_through_student_completion(): void
    {
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->mockPlnPreviewGenerationAlwaysReady();

        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/approve", [
                'nomor_surat' => 'PLN-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->createReadyPlnMahasiswaArtifact($application->fresh());
        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/generated-preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_COMPLETED,
        ]);
    }

    public function test_proses_luar_negeri_tendik_approval_requires_nomor_surat(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nomor_surat']);

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'nomor_surat' => null,
        ]);
    }

    public function test_magang_uses_canonical_approval_flow_through_student_completion(): void
    {
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');

        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student);

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat_pengantar' => 'MAG-PENGANTAR-001',
                'nomor_surat_tugas' => 'MAG-TUGAS-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->createReadyMagangMahasiswaArtifact($application->fresh());

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-pengantar-magang/{$application->id}/generated-preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-pengantar-magang/{$application->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_COMPLETED,
        ]);
    }

    public function test_beasiswa_uses_canonical_approval_flow_through_student_completion(): void
    {
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');

        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student);

        $this->mockBeasiswaPreviewGenerationForApprove();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve", [
                'nomor_surat' => 'BEA-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'BEA-001',
            'assigned_to' => $tendik->id,
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->mockBeasiswaPreviewGenerationForProdiApprove();

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->mockBeasiswaPreviewGenerationForDepartmentApprove();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->createReadyBeasiswaMahasiswaArtifact($application->fresh());

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_COMPLETED,
        ]);
    }

    public function test_beasiswa_tendik_approval_requires_nomor_surat(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->scholarshipApplication();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nomor_surat']);

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'nomor_surat' => null,
            'tendik_approved_by' => null,
        ]);
    }

    private function createReadyAktifMahasiswaArtifact(SuratKeteranganAktifApplication $application): LetterDocumentArtifact
    {
        $phase = LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW;
        $pdfPath = 'letter-document-artifacts/'
            . SuratKeteranganAktifApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/preview_1.pdf';

        Storage::disk('local')->put($pdfPath, "%PDF-1.4\nmahasiswa_review");

        return LetterDocumentArtifact::create([
            'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => 1,
            'docx_path' => 'letter-document-artifacts/'
                . SuratKeteranganAktifApplication::LETTER_TYPE
                . '/'
                . $application->id
                . '/'
                . $phase
                . '/source_1.docx',
            'pdf_path' => $pdfPath,
            'source_hash' => hash('sha256', $application->id . '|' . $phase . '|approval-transition'),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => now(),
        ]);
    }

    private function createReadyBeasiswaMahasiswaArtifact(ScholarshipApplication $application): LetterDocumentArtifact
    {
        $phase = LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW;
        $pdfPath = 'letter-document-artifacts/'
            . ScholarshipApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/preview_1.pdf';

        Storage::disk('local')->put($pdfPath, "%PDF-1.4\nmahasiswa_review");

        return LetterDocumentArtifact::create([
            'letter_type' => ScholarshipApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => 1,
            'docx_path' => 'letter-document-artifacts/'
                . ScholarshipApplication::LETTER_TYPE
                . '/'
                . $application->id
                . '/'
                . $phase
                . '/source_1.docx',
            'pdf_path' => $pdfPath,
            'source_hash' => hash('sha256', $application->id . '|' . $phase . '|approval-transition'),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => now(),
        ]);
    }

    private function createReadyPlnMahasiswaArtifact(ProsesLuarNegeriApplication $application): LetterDocumentArtifact
    {
        $phase = LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW;
        $pdfPath = 'letter-document-artifacts/'
            . ProsesLuarNegeriApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/preview_1.pdf';

        Storage::disk('local')->put($pdfPath, "%PDF-1.4\nmahasiswa_review");

        return LetterDocumentArtifact::create([
            'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => 1,
            'docx_path' => 'letter-document-artifacts/'
                . ProsesLuarNegeriApplication::LETTER_TYPE
                . '/'
                . $application->id
                . '/'
                . $phase
                . '/source_1.docx',
            'pdf_path' => $pdfPath,
            'source_hash' => hash('sha256', $application->id . '|' . $phase . '|approval-transition'),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => now(),
        ]);
    }

    private function createReadyMagangMahasiswaArtifact(SuratPengantarMagangApplication $application): LetterDocumentArtifact
    {
        $phase = LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW;
        $pdfPath = 'letter-document-artifacts/'
            . SuratPengantarMagangApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/preview_1.pdf';

        Storage::disk('local')->put($pdfPath, "%PDF-1.4\nmahasiswa_review");

        return LetterDocumentArtifact::create([
            'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
            'application_id' => $application->id,
            'phase' => $phase,
            'version' => 1,
            'docx_path' => 'letter-document-artifacts/'
                . SuratPengantarMagangApplication::LETTER_TYPE
                . '/'
                . $application->id
                . '/'
                . $phase
                . '/source_1.docx',
            'pdf_path' => $pdfPath,
            'source_hash' => hash('sha256', $application->id . '|' . $phase . '|approval-transition'),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => now(),
        ]);
    }
}
