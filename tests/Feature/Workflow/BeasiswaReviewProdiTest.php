<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the regression where the Beasiswa Tendik/Akademik show endpoints returned
 * student.prodi = null because they read the legacy mahasiswa_profiles.program_studi
 * column instead of the canonical users.study_program -> study_programs.name path.
 */
class BeasiswaReviewProdiTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_tendik_scholarship_show_returns_canonical_prodi(): void
    {
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student, [
            'scholarship_name' => 'Beasiswa Prodi Canonical',
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}");

        $response->assertOk();
        $this->assertSame($program->name, $response->json('student.prodi'));
        $this->assertNotNull($response->json('student.prodi'));
        $this->assertNotSame('null', $response->json('student.prodi'));
        $this->assertNotEmpty($response->json('student.fakultas'));
        $this->assertSame($student->name, $response->json('student.name'));
    }

    public function test_akademik_scholarship_show_returns_canonical_prodi(): void
    {
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student, [
            'scholarship_name' => 'Beasiswa Prodi Canonical Akademik',
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $program->id]);

        $response = $this->actingAs($kaprodi, 'sanctum')
            ->getJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}");

        $response->assertOk();
        $this->assertSame($program->name, $response->json('student.prodi'));
        $this->assertNotNull($response->json('student.prodi'));
        $this->assertNotSame('null', $response->json('student.prodi'));
    }

    public function test_akademik_scholarship_show_returns_canonical_prodi_for_kadep_stage(): void
    {
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        $department = $program->department;
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $kadep = $this->akademik('kadep', ['department_id' => $department->id]);

        $response = $this->actingAs($kadep, 'sanctum')
            ->getJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}");

        $response->assertOk();
        $this->assertSame($program->name, $response->json('student.prodi'));
        $this->assertSame($department->name, $response->json('student.departemen'));
    }
}
