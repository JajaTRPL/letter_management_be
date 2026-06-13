<?php

namespace Tests\Feature\Workflow;

use App\Models\SuratTugasApplication;
use App\Services\DocumentConverter;
use App\Services\SuratTugasDocumentGenerationService;
use App\Services\SuratTugasPreviewGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Feature\Workflow\Support\SuratTugasFakeDocumentConverter;
use Tests\Feature\Workflow\Support\SuratTugasFakeDocumentGenerationService;

/**
 * End-to-end Surat Tugas runtime: private draft uploads, submit, the full
 * Tendik → Prodi → Departemen → Mahasiswa-review → Completed flow, and the
 * private supporting-upload lifecycle (private disk, replacement cleanup,
 * no public copy, validation gate).
 */
class SuratTugasWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-25 09:10:20'));
        Cache::flush();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_save_draft_stores_supporting_uploads_on_private_disk_not_public(): void
    {
        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-tugas/draft', [
                'nama_perusahaan' => 'PT Privat',
                'kegiatan' => 'Magang',
                'posisi' => 'Intern',
                'dosen_pembimbing_dpa' => 'Dr. Test',
                'tgl_mulai' => '2026-06-01',
                'tgl_selesai' => '2026-08-31',
                'proposal_kegiatan_magang' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
                'surat_pengantar_magang' => UploadedFile::fake()->create('pengantar.pdf', 100, 'application/pdf'),
            ])
            ->assertOk();

        $application = SuratTugasApplication::where('user_id', $student->id)->latest()->firstOrFail();

        // The real private files live in the attachment registry under the
        // canonical private prefix. D2H3D leaves legacy *_path columns unchanged;
        // submit gates use the registry rows.
        $rows = \App\Models\LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $application->id)
            ->get()
            ->keyBy('document_key');
        $this->assertStringStartsWith(
            'letter-application-attachments/surat-tugas/proposal/',
            $rows['proposal']->storage_path,
        );
        $this->assertStringStartsWith(
            'letter-application-attachments/surat-tugas/surat-pengantar-magang/',
            $rows['surat_pengantar_magang']->storage_path,
        );
        $this->assertSame('local', $rows['proposal']->storage_disk);
        $this->assertTrue(Storage::disk('local')->exists($rows['proposal']->storage_path));
        $this->assertTrue(Storage::disk('local')->exists($rows['surat_pengantar_magang']->storage_path));

        // Legacy columns are not rewritten with markers, raw paths, or URLs.
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($application->proposal_kegiatan_magang_path);
        $this->assertNull($application->surat_pengantar_magang_path);
    }

    public function test_save_draft_replacement_cleans_old_private_file(): void
    {
        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-tugas/draft', [
                'proposal_kegiatan_magang' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
            ])
            ->assertOk();
        $first = SuratTugasApplication::where('user_id', $student->id)->latest()->firstOrFail();
        // D2B: the real private file is the registry row's storage_path.
        $firstPath = \App\Models\LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $first->id)
            ->where('document_key', 'proposal')
            ->firstOrFail()
            ->storage_path;
        $this->assertTrue(Storage::disk('local')->exists($firstPath));

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-tugas/draft', [
                'proposal_kegiatan_magang' => UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
            ])
            ->assertOk();
        $secondPath = \App\Models\LetterApplicationAttachment::query()
            ->where('letter_type', SuratTugasApplication::LETTER_TYPE)
            ->where('application_id', $first->id)
            ->where('document_key', 'proposal')
            ->firstOrFail()
            ->storage_path;

        $this->assertNotSame($firstPath, $secondPath);
        $this->assertFalse(Storage::disk('local')->exists($firstPath), 'Replaced draft upload must be deleted.');
        $this->assertTrue(Storage::disk('local')->exists($secondPath));
    }

    public function test_submit_validates_required_fields_and_supporting_pdfs(): void
    {
        [$student] = $this->completeMahasiswa();
        // Draft with NO fields and NO uploads.
        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-tugas/draft', [])
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-tugas/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nama_perusahaan',
                'kegiatan',
                'posisi',
                'dosen_pembimbing_dpa',
                'tgl_mulai',
                'tgl_selesai',
                'proposal_kegiatan_magang_path',
                'surat_pengantar_magang_path',
            ]);

        $this->assertSame(
            SuratTugasApplication::STATUS_DRAFT,
            SuratTugasApplication::where('user_id', $student->id)->latest()->firstOrFail()->status,
        );
    }

    public function test_submit_rejects_end_date_before_start_date(): void
    {
        [$student] = $this->completeMahasiswa();
        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-tugas/draft', [
                'nama_perusahaan' => 'PT X',
                'kegiatan' => 'Magang',
                'posisi' => 'Intern',
                'dosen_pembimbing_dpa' => 'Dr. Test',
                'tgl_mulai' => '2026-08-31',
                'tgl_selesai' => '2026-06-01',
                'proposal_kegiatan_magang' => UploadedFile::fake()->create('p.pdf', 100, 'application/pdf'),
                'surat_pengantar_magang' => UploadedFile::fake()->create('s.pdf', 100, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tgl_selesai']);
    }

    public function test_full_workflow_draft_through_completed_and_final_download(): void
    {
        $this->bindPreviewStack();

        $tendik = $this->tendikPersuratan([SuratTugasApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();

        // 1. Draft with fields + private uploads.
        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-tugas/draft', [
                'nama_perusahaan' => 'PT Selesai',
                'kegiatan' => 'Magang Kerja Praktik',
                'posisi' => 'Software Engineer Intern',
                'dosen_pembimbing_dpa' => 'Dr. Test',
                'tgl_mulai' => '2026-06-01',
                'tgl_selesai' => '2026-08-31',
                'proposal_kegiatan_magang' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
                'surat_pengantar_magang' => UploadedFile::fake()->create('pengantar.pdf', 100, 'application/pdf'),
            ])
            ->assertOk();
        $application = SuratTugasApplication::where('user_id', $student->id)->latest()->firstOrFail();

        // 2. Submit.
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-tugas/submit')
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        // 3. Tendik approve with nomor_surat_tugas.
        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-tugas/{$application->id}/approve", [
                'nomor_surat_tugas' => 'ST/FLOW/001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_APPROVED_TENDIK);

        // 4. Kaprodi approve.
        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_APPROVED_KAPRODI);

        // 5. Kadep approve → ready for student review.
        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-tugas/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_READY_FOR_STUDENT_REVIEW);

        // 6. Student completes.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-tugas/{$application->id}/complete")
            ->assertOk()
            ->assertJsonPath('application.status', SuratTugasApplication::STATUS_COMPLETED);

        $fresh = $application->fresh();
        $this->assertNotNull($fresh->completed_at);
        $this->assertNotNull($fresh->student_reviewed_at);

        // 7. Final download serves the READY mahasiswa_review PDF.
        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-tugas/{$application->id}/final-download");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // No public artifact ever produced.
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function bindPreviewStack(): void
    {
        $this->app->instance(SuratTugasDocumentGenerationService::class, new SuratTugasFakeDocumentGenerationService());
        $this->app->instance(DocumentConverter::class, new SuratTugasFakeDocumentConverter());
        $this->app->forgetInstance(SuratTugasPreviewGenerationService::class);
    }
}
