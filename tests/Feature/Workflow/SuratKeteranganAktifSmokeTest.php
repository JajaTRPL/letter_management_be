<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratKeteranganAktifApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuratKeteranganAktifSmokeTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_mahasiswa_can_save_submit_and_list_canonical_ska_application(): void
    {
        $this->mockSkaPreviewGenerationAlwaysReady();
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();

        $draftResponse = $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-keterangan-aktif/draft', $this->validDraftPayload([
                'keperluan' => 'Verifikasi status aktif untuk administrasi beasiswa',
            ]))
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_DRAFT);

        $applicationId = $draftResponse->json('application.id');

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $applicationId,
            'user_id' => $student->id,
            'keperluan' => 'Verifikasi status aktif untuk administrasi beasiswa',
            'status' => SuratKeteranganAktifApplication::STATUS_DRAFT,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-keterangan-aktif/submit')
            ->assertOk()
            ->assertJsonPath('application.id', $applicationId)
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $applicationId,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
        ]);

        $applications = $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-keterangan-aktif/applications')
            ->assertOk()
            ->json('applications');

        $this->assertTrue(collect($applications)->contains(
            fn (array $application): bool => $application['id'] === $applicationId
                && $application['status'] === SuratKeteranganAktifApplication::STATUS_SUBMITTED
        ));
    }

    public function test_tendik_can_request_revision_and_mahasiswa_can_resubmit_canonical_ska(): void
    {
        $this->mockSkaPreviewGenerationAlwaysReady();
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, ['assigned_to' => $tendik->id]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/revise", [
                'note' => 'Mohon perjelas keperluan pengajuan.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_REVISION);

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_REVISION,
            'revision_note' => 'Mohon perjelas keperluan pengajuan.',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-keterangan-aktif/draft')
            ->assertOk()
            ->assertJsonPath('application.id', $application->id)
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_REVISION);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-keterangan-aktif/draft', $this->validDraftPayload([
                'keperluan' => 'Keperluan sudah diperjelas setelah revisi',
            ]))
            ->assertOk()
            ->assertJsonPath('application.id', $application->id);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-keterangan-aktif/submit')
            ->assertOk()
            ->assertJsonPath('application.id', $application->id)
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_SUBMITTED);

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
            'revision_note' => null,
            'rejection_reason' => null,
        ]);
    }

    public function test_tendik_can_reject_canonical_ska_application(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication(null, ['assigned_to' => $tendik->id]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/reject", [
                'reason' => 'Data pengajuan tidak valid.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_REJECTED);

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_REJECTED,
            'rejection_reason' => 'Data pengajuan tidak valid.',
        ]);
    }

    public function test_canonical_ska_approval_preview_and_completion_flow(): void
    {
        Notification::fake();
        Storage::fake('local');
        $this->mockSkaPreviewGenerationAlwaysReady();

        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();

        $draftResponse = $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-keterangan-aktif/draft', $this->validDraftPayload())
            ->assertOk();

        $applicationId = $draftResponse->json('application.id');

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-keterangan-aktif/submit')
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_SUBMITTED);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$applicationId}/approve", [
                'nomor_surat' => 'AKT-SMOKE-001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK);

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $applicationId,
            'nomor_surat' => 'AKT-SMOKE-001',
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$applicationId}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$applicationId}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW);

        $this->createReadyMahasiswaArtifact(SuratKeteranganAktifApplication::findOrFail($applicationId));

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-keterangan-aktif/{$applicationId}/generated-preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-keterangan-aktif/{$applicationId}/complete")
            ->assertOk()
            ->assertJsonPath('application.status', SuratKeteranganAktifApplication::STATUS_COMPLETED);

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $applicationId,
            'status' => SuratKeteranganAktifApplication::STATUS_COMPLETED,
        ]);
    }

    private function validDraftPayload(array $overrides = []): array
    {
        return array_merge([
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'jenis_kelamin' => 'Laki-laki',
            'keperluan' => 'Keperluan administrasi aktif kuliah',
            'nama_orang_tua_wali' => 'Orang Tua Test',
            'pekerjaan_orang_tua_wali' => 'Pegawai',
            'nip_orang_tua_wali' => null,
            'pangkat_gol_orang_tua_wali' => null,
            'instansi_orang_tua_wali' => null,
        ], $overrides);
    }

    private function createReadyMahasiswaArtifact(SuratKeteranganAktifApplication $application): LetterDocumentArtifact
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
            'source_hash' => hash('sha256', $application->id . '|' . $phase . '|smoke'),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => now(),
        ]);
    }
}
