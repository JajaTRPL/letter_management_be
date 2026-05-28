<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportingDocumentPreviewTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const PDF_BYTES = "%PDF-1.4\n%fake test pdf body\n%%EOF\n";

    private function makeScholarshipWithUploads(?User $student = null): ScholarshipApplication
    {
        Storage::fake('public');

        [$student, $profile] = $student
            ? [$student, $student->mahasiswaProfile]
            : $this->completeMahasiswa();

        Storage::disk('public')->put('scholarships/transcripts/transkrip.pdf', self::PDF_BYTES);
        Storage::disk('public')->put('scholarships/slips/slip-ayah.pdf', self::PDF_BYTES);
        Storage::disk('public')->put('scholarships/slips/slip-ibu.pdf', self::PDF_BYTES);

        return ScholarshipApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $profile?->id,
            'scholarship_name' => 'Beasiswa Test',
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/transkrip.pdf'),
            'slip_gaji_ayah_path' => Storage::url('scholarships/slips/slip-ayah.pdf'),
            'slip_gaji_ibu_path' => Storage::url('scholarships/slips/slip-ibu.pdf'),
        ]);
    }

    private function previewPath(ScholarshipApplication $application, string $field): string
    {
        return "/api/scholarship/{$application->id}/supporting-documents/{$field}/preview";
    }

    private function assertFetchOnlyPdfPayload($response): void
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        $this->assertEmpty($response->headers->get('Content-Disposition') ?? '');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_persuratan_tendik_can_preview_transkrip_nilai(): void
    {
        $application = $this->makeScholarshipWithUploads();
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($tendik, 'sanctum')->get($this->previewPath($application, 'transkrip_nilai'))
        );
    }

    public function test_persuratan_tendik_can_preview_slip_gaji_ayah(): void
    {
        $application = $this->makeScholarshipWithUploads();
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($tendik, 'sanctum')->get($this->previewPath($application, 'slip_gaji_ayah'))
        );
    }

    public function test_persuratan_tendik_can_preview_slip_gaji_ibu(): void
    {
        $application = $this->makeScholarshipWithUploads();
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($tendik, 'sanctum')->get($this->previewPath($application, 'slip_gaji_ibu'))
        );
    }

    public function test_non_persuratan_tendik_cannot_preview(): void
    {
        $application = $this->makeScholarshipWithUploads();
        $tendikSarpras = $this->tendikSarpras();

        $this->actingAs($tendikSarpras, 'sanctum')
            ->get($this->previewPath($application, 'transkrip_nilai'))
            ->assertForbidden();
    }

    public function test_persuratan_tendik_without_beasiswa_assignment_cannot_preview(): void
    {
        $application = $this->makeScholarshipWithUploads();
        // Persuratan but assigned to a different letter type — canHandle returns false for Beasiswa.
        $tendik = $this->tendikPersuratan([\App\Models\SuratPengantarMagangApplication::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->get($this->previewPath($application, 'transkrip_nilai'))
            ->assertForbidden();
    }

    public function test_owning_mahasiswa_can_preview_own_supporting_doc(): void
    {
        $application = $this->makeScholarshipWithUploads();
        $owner = User::find($application->user_id);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($owner, 'sanctum')->get($this->previewPath($application, 'transkrip_nilai'))
        );
    }

    public function test_other_mahasiswa_cannot_preview(): void
    {
        $application = $this->makeScholarshipWithUploads();
        [$intruder] = $this->completeMahasiswa();

        $this->actingAs($intruder, 'sanctum')
            ->get($this->previewPath($application, 'transkrip_nilai'))
            ->assertForbidden();
    }

    public function test_scoped_kaprodi_can_preview(): void
    {
        $studyProgram = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $studyProgram);
        $application = $this->makeScholarshipWithUploads($student);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $studyProgram->id]);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($kaprodi, 'sanctum')->get($this->previewPath($application, 'transkrip_nilai'))
        );
    }

    public function test_wrong_prodi_kaprodi_cannot_preview(): void
    {
        $studyProgramA = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $studyProgramA);
        $application = $this->makeScholarshipWithUploads($student);

        $otherProgram = $this->studyProgram();
        $foreignKaprodi = $this->akademik('kaprodi', ['study_program_id' => $otherProgram->id]);

        $this->actingAs($foreignKaprodi, 'sanctum')
            ->get($this->previewPath($application, 'transkrip_nilai'))
            ->assertForbidden();
    }

    public function test_scoped_kadep_can_preview(): void
    {
        $studyProgram = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $studyProgram);
        $application = $this->makeScholarshipWithUploads($student);

        $kadep = $this->akademik('kadep', ['department_id' => $studyProgram->department_id]);

        $this->assertFetchOnlyPdfPayload(
            $this->actingAs($kadep, 'sanctum')->get($this->previewPath($application, 'transkrip_nilai'))
        );
    }

    public function test_wrong_department_kadep_cannot_preview(): void
    {
        $studyProgramA = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $studyProgramA);
        $application = $this->makeScholarshipWithUploads($student);

        $otherDepartment = $this->department();
        $foreignKadep = $this->akademik('kadep', ['department_id' => $otherDepartment->id]);

        $this->actingAs($foreignKadep, 'sanctum')
            ->get($this->previewPath($application, 'transkrip_nilai'))
            ->assertForbidden();
    }

    public function test_invalid_field_is_rejected_safely(): void
    {
        $application = $this->makeScholarshipWithUploads();
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->get($this->previewPath($application, 'generated_docx'));

        $response->assertNotFound();
        $this->assertEmpty($response->headers->get('Content-Disposition') ?? '');
    }

    public function test_missing_file_returns_safe_not_found(): void
    {
        $application = $this->makeScholarshipWithUploads();
        Storage::disk('public')->delete('scholarships/transcripts/transkrip.pdf');

        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->get($this->previewPath($application, 'transkrip_nilai'))
            ->assertNotFound();
    }

    public function test_unauthenticated_user_is_denied(): void
    {
        $application = $this->makeScholarshipWithUploads();

        $this->get($this->previewPath($application, 'transkrip_nilai'))
            ->assertStatus(401);
    }

    public function test_path_outside_allowed_prefix_is_rejected(): void
    {
        $application = $this->makeScholarshipWithUploads();
        // Plant a path that is NOT under scholarships/transcripts/ or scholarships/slips/.
        $application->forceFill([
            'transkrip_nilai_path' => Storage::url('scholarships/sample.docx'),
        ])->save();
        Storage::disk('public')->put('scholarships/sample.docx', 'not allowed');

        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->get($this->previewPath($application, 'transkrip_nilai'))
            ->assertNotFound();
    }

    public function test_generic_storage_route_still_serves_attachment(): void
    {
        $application = $this->makeScholarshipWithUploads();
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->get('/api/storage/scholarships/transcripts/transkrip.pdf');

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringStartsWith('attachment', strtolower($disposition));
    }
}
