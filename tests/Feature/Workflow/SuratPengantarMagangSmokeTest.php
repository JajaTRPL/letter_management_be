<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\SuratPengantarMagangApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Minimal regression scaffold for the Magang workflow. Pins today's
 * behavior after the private artifact cutover and legacy bridge retirement.
 */
class SuratPengantarMagangSmokeTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_mahasiswa_can_save_submit_and_list_magang_application(): void
    {
        Storage::fake('public');
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();

        $proposal = UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf');

        $draftResponse = $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', array_merge(
                $this->validDraftPayload(),
                ['proposal_kegiatan_magang' => $proposal],
            ))
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_DRAFT);

        $applicationId = $draftResponse->json('application.id');

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $applicationId,
            'user_id' => $student->id,
            'nama_perusahaan' => 'PT Test',
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
        ]);
        $this->assertNotNull(
            SuratPengantarMagangApplication::find($applicationId)->proposal_kegiatan_magang_path,
            'Proposal upload must persist a storage path.',
        );

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertOk()
            ->assertJsonPath('application.id', $applicationId)
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $applicationId,
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
        ]);

        $applications = $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-pengantar-magang/applications')
            ->assertOk()
            ->json('applications');

        $this->assertTrue(collect($applications)->contains(
            fn (array $application): bool => $application['id'] === $applicationId
                && $application['status'] === SuratPengantarMagangApplication::STATUS_SUBMITTED
        ));
    }

    public function test_tendik_can_revise_and_mahasiswa_can_resubmit_magang(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, ['assigned_to' => $tendik->id]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/revise", [
                'note' => 'Mohon perbaiki nama perusahaan.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_REVISION);

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_REVISION,
            'revision_note' => 'Mohon perbaiki nama perusahaan.',
        ]);

        // Existing proposal path is preserved, so resubmit does not need a new file upload.
        $this->actingAs($student, 'sanctum')
            ->postJson(
                '/api/mahasiswa/surat-pengantar-magang/draft',
                $this->validDraftPayload(['nama_perusahaan' => 'PT Test Revisi']),
            )
            ->assertOk()
            ->assertJsonPath('application.id', $application->id);

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertOk()
            ->assertJsonPath('application.id', $application->id)
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_SUBMITTED);

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
            'revision_note' => null,
            'rejection_reason' => null,
        ]);
    }

    public function test_tendik_can_reject_magang_application(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, ['assigned_to' => $tendik->id]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/reject", [
                'reason' => 'Data tidak lengkap.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_REJECTED);

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_REJECTED,
            'rejection_reason' => 'Data tidak lengkap.',
        ]);
    }

    public function test_magang_full_approval_preview_and_completion_flow(): void
    {
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');

        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, ['assigned_to' => $tendik->id]);

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat_pengantar' => 'MAG-SMOKE-PENGANTAR-001',
                'nomor_surat_tugas' => 'MAG-SMOKE-TUGAS-001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK);

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'nomor_surat' => 'MAG-SMOKE-PENGANTAR-001',
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW);

        $this->createReadyMahasiswaArtifact($application->fresh());

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-pengantar-magang/{$application->id}/generated-preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-pengantar-magang/{$application->id}/complete")
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_COMPLETED);

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_COMPLETED,
        ]);
    }

    private function validDraftPayload(array $overrides = []): array
    {
        return array_merge([
            'nama_penerima' => 'HR Department',
            'jabatan_penerima' => 'Kepala Divisi Teknologi',
            'nama_perusahaan' => 'PT Test',
            'alamat_perusahaan' => 'Jl. Test No. 1, Sleman',
            'alamat_jalan' => 'Jl. Test No. 1',
            'alamat_kelurahan' => 'Caturtunggal',
            'alamat_kecamatan' => 'Depok',
            'alamat_kota_kabupaten' => 'Sleman',
            'alamat_provinsi' => 'Daerah Istimewa Yogyakarta',
            'kode_pos' => '55281',
            'peran' => 'Software Engineer Intern',
            'rentang_tanggal' => '1 Juni 2026 - 31 Agustus 2026',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
            'dosen_pembimbing_dpa' => 'Dr. Test',
        ], $overrides);
    }

    private function createReadyMahasiswaArtifact(SuratPengantarMagangApplication $application): LetterDocumentArtifact
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
            'source_hash' => hash('sha256', $application->id . '|' . $phase . '|smoke'),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => now(),
        ]);
    }
}
