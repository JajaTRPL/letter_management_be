<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Minimal regression scaffold for the PLN (Proses Luar Negeri) workflow.
 * Mirrors the existing SKA smoke test shape so future standardization work
 * can replace either pipeline without breaking baseline contracts.
 *
 * Document generation is mocked here to keep the smoke test focused on
 * workflow transitions. Dedicated PLN artifact tests cover private
 * generation and protected streaming.
 */
class ProsesLuarNegeriSmokeTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_mahasiswa_can_save_submit_and_list_pln_application(): void
    {
        $this->mockPlnPreviewGenerationAlwaysReady();
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();

        $draftResponse = $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/proses-luar-negeri/draft', $this->validDraftPayload([
                'keperluan' => 'Rekomendasi student exchange ke Tokyo',
            ]))
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_DRAFT);

        $applicationId = $draftResponse->json('application.id');

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $applicationId,
            'user_id' => $student->id,
            'keperluan' => 'Rekomendasi student exchange ke Tokyo',
            'status' => ProsesLuarNegeriApplication::STATUS_DRAFT,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/proses-luar-negeri/submit')
            ->assertOk()
            ->assertJsonPath('application.id', $applicationId)
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $applicationId,
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
        ]);

        $applications = $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/proses-luar-negeri/applications')
            ->assertOk()
            ->json('applications');

        $this->assertTrue(collect($applications)->contains(
            fn (array $application): bool => $application['id'] === $applicationId
                && $application['status'] === ProsesLuarNegeriApplication::STATUS_SUBMITTED
        ));
    }

    public function test_tendik_can_revise_and_mahasiswa_can_resubmit_pln(): void
    {
        $this->mockPlnPreviewGenerationAlwaysReady();
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, ['assigned_to' => $tendik->id]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/revise", [
                'note' => 'Mohon lampirkan dokumen pendukung.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_REVISION);

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_REVISION,
            'revision_note' => 'Mohon lampirkan dokumen pendukung.',
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/proses-luar-negeri/draft', $this->validDraftPayload([
                'keperluan' => 'Dokumen pendukung sudah dilengkapi setelah revisi',
            ]))
            ->assertOk()
            ->assertJsonPath('application.id', $application->id);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/proses-luar-negeri/submit')
            ->assertOk()
            ->assertJsonPath('application.id', $application->id)
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_SUBMITTED);

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'revision_note' => null,
            'rejection_reason' => null,
        ]);
    }

    public function test_tendik_can_reject_pln_application(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, ['assigned_to' => $tendik->id]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/reject", [
                'reason' => 'Data tidak lengkap.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_REJECTED);

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_REJECTED,
            'rejection_reason' => 'Data tidak lengkap.',
        ]);
    }

    public function test_pln_full_approval_preview_and_completion_flow(): void
    {
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->mockPlnPreviewGenerationAlwaysReady();

        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();

        $draftResponse = $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/proses-luar-negeri/draft', $this->validDraftPayload())
            ->assertOk();

        $applicationId = $draftResponse->json('application.id');

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/proses-luar-negeri/submit')
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_SUBMITTED);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$applicationId}/approve", [
                'nomor_surat' => 'PLN-SMOKE-001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK);

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $applicationId,
            'nomor_surat' => 'PLN-SMOKE-001',
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$applicationId}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI);

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$applicationId}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW)
            ->assertJsonPath('application.generated_pdf_path', null);

        $this->createReadyPlnMahasiswaArtifact(ProsesLuarNegeriApplication::findOrFail($applicationId));
        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$applicationId}/generated-preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$applicationId}/complete")
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_COMPLETED);

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $applicationId,
            'status' => ProsesLuarNegeriApplication::STATUS_COMPLETED,
        ]);
    }

    private function validDraftPayload(array $overrides = []): array
    {
        return array_merge([
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'jenis_kelamin' => 'Laki-laki',
            'semester' => 4,
            'nomor_paspor' => 'A1234567',
            'keperluan' => 'Rekomendasi pendaftaran konferensi internasional',
        ], $overrides);
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
            'source_hash' => hash('sha256', $application->id . '|' . $phase . '|1|ready'),
            'status' => LetterDocumentArtifact::STATUS_READY,
            'generated_at' => now(),
        ]);
    }
}
