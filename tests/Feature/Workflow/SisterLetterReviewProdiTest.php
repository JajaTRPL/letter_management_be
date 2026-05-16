<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the regression where SKA / Magang / PLN review endpoints returned
 * a payload whose mahasiswa_profile.{program_studi,fakultas} were null for
 * admin-created accounts (and are being deprecated by the canonical academic
 * relation tree). After this fix, showForReviewer eager-loads the canonical
 * user.studyProgram.department.faculty chain so the FE can render real names.
 */
class SisterLetterReviewProdiTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_ska_show_for_reviewer_payload_exposes_canonical_study_program_chain(): void
    {
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        $department = $program->department;
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
        ]);

        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-keterangan-aktif/{$application->id}");

        $response->assertOk();
        $this->assertSame($program->name, $response->json('application.user.study_program.name'));
        $this->assertSame($department->name, $response->json('application.user.study_program.department.name'));
        $this->assertSame($department->faculty->name, $response->json('application.user.study_program.department.faculty.name'));
    }

    public function test_ska_akademik_show_for_reviewer_also_exposes_canonical_chain(): void
    {
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
        ]);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $program->id]);

        $response = $this->actingAs($kaprodi, 'sanctum')
            ->getJson("/api/akademik/surat-keterangan-aktif/{$application->id}");

        $response->assertOk();
        $this->assertSame($program->name, $response->json('application.user.study_program.name'));
        $this->assertNotNull($response->json('application.user.study_program.department.name'));
        $this->assertNotNull($response->json('application.user.study_program.department.faculty.name'));
    }

    public function test_magang_show_for_reviewer_payload_exposes_canonical_chain(): void
    {
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        $department = $program->department;
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-pengantar-magang/{$application->id}");

        $response->assertOk();
        $this->assertSame($program->name, $response->json('application.user.study_program.name'));
        $this->assertSame($department->name, $response->json('application.user.study_program.department.name'));
    }

    public function test_magang_akademik_show_for_reviewer_payload_exposes_canonical_chain(): void
    {
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
        ]);

        $sekprodi = $this->akademik('sekprodi', ['study_program_id' => $program->id]);

        $response = $this->actingAs($sekprodi, 'sanctum')
            ->getJson("/api/akademik/surat-pengantar-magang/{$application->id}");

        $response->assertOk();
        $this->assertSame($program->name, $response->json('application.user.study_program.name'));
    }

    public function test_pln_show_for_reviewer_payload_exposes_canonical_chain(): void
    {
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        $department = $program->department;
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
        ]);

        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/proses-luar-negeri/{$application->id}");

        $response->assertOk();
        $this->assertSame($program->name, $response->json('application.user.study_program.name'));
        $this->assertSame($department->name, $response->json('application.user.study_program.department.name'));
    }

    public function test_pln_akademik_show_for_reviewer_payload_exposes_canonical_chain(): void
    {
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        $department = $program->department;
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $sekdep = $this->akademik('sekdep', ['department_id' => $department->id]);

        $response = $this->actingAs($sekdep, 'sanctum')
            ->getJson("/api/akademik/proses-luar-negeri/{$application->id}");

        $response->assertOk();
        $this->assertSame($program->name, $response->json('application.user.study_program.name'));
        $this->assertSame($department->name, $response->json('application.user.study_program.department.name'));
    }

    public function test_beasiswa_show_still_returns_canonical_student_fakultas_and_departemen(): void
    {
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        $department = $program->department;
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}");

        $response->assertOk();
        $this->assertSame($program->name, $response->json('student.prodi'));
        $this->assertSame($department->name, $response->json('student.departemen'));
        $this->assertNotNull($response->json('student.fakultas'));
    }
}
