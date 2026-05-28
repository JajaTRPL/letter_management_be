<?php

namespace Tests\Feature\Workflow;

use App\Models\AcademicPeriod;
use App\Models\ScholarshipApplication;
use App\Models\StudyProgram;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\ProsesLuarNegeriApplication;
use App\Services\MahasiswaProfileDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MahasiswaProfileSummaryContractTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_summary_uses_canonical_sources_formats_birth_date_and_computes_semester(): void
    {
        $department = $this->department([
            'code' => 'DTEDI',
            'name' => 'Departemen Teknik Elektro dan Informatika',
        ]);
        $program = $this->studyProgram($department, [
            'code' => 'TRPL',
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
        ]);
        AcademicPeriod::create([
            'academic_year' => '2025/2026',
            'year_start' => 2025,
            'semester_type' => AcademicPeriod::SEMESTER_TYPE_GENAP,
            'semester_order' => 2,
            'start_date' => today()->subDay()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        [$student] = $this->completeMahasiswa([
            'name' => 'Beno Canonical Student',
            'email' => 'beno.student@example.test',
        ], [
            'nim' => '22/493038/SV/20654',
            'fakultas' => 'Legacy Faculty',
            'program_studi' => 'Legacy Study Program',
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'jenis_kelamin' => 'L',
        ], $program);

        $summary = app(MahasiswaProfileDataService::class)->profileSummaryForUser($student->fresh());

        $this->assertSame('Beno Canonical Student', $summary['full_name']);
        $this->assertSame('22/493038/SV/20654', $summary['nim']);
        $this->assertSame('beno.student@example.test', $summary['email']);
        $this->assertSame($department->faculty->name, $summary['faculty']);
        $this->assertSame('Teknologi Rekayasa Perangkat Lunak', $summary['study_program']);
        $this->assertSame('TRPL', $summary['study_program_code']);
        $this->assertSame('Departemen Teknik Elektro dan Informatika', $summary['department']);
        $this->assertSame('Sleman', $summary['tempat_lahir']);
        $this->assertSame('2004-05-04', $summary['tanggal_lahir']);
        $this->assertSame('L', $summary['jenis_kelamin']);
        $this->assertSame(8, $summary['current_semester']);
    }

    public function test_summary_uses_legacy_academic_text_only_when_relations_are_absent_and_keeps_semester_null(): void
    {
        $student = $this->activeUser([
            'role' => 'mahasiswa',
            'name' => 'Legacy Student',
            'email' => 'legacy.student@example.test',
            'study_program_id' => null,
            'department_id' => null,
        ]);
        $student->mahasiswaProfile()->create([
            'nim' => null,
            'fakultas' => 'Legacy Faculty',
            'program_studi' => 'Legacy Study Program',
            'tanggal_lahir' => null,
        ]);

        $summary = app(MahasiswaProfileDataService::class)->profileSummaryForUser($student->fresh());

        $this->assertSame('Legacy Faculty', $summary['faculty']);
        $this->assertSame('Legacy Study Program', $summary['study_program']);
        $this->assertNull($summary['study_program_code']);
        $this->assertNull($summary['department']);
        $this->assertNull($summary['nim']);
        $this->assertNull($summary['tanggal_lahir']);
        $this->assertNull($summary['current_semester']);
    }

    public function test_application_summary_uses_user_profile_when_legacy_application_profile_link_is_missing(): void
    {
        [$student, $program] = $this->canonicalStudent();
        $application = $this->magangApplication($student, [
            'mahasiswa_profile_id' => null,
        ]);

        $summary = app(MahasiswaProfileDataService::class)->profileSummaryForApplication($application->fresh());

        $this->assertSame('Endpoint Student', $summary['full_name']);
        $this->assertSame('22/493038/SV/20654', $summary['nim']);
        $this->assertSame($program->name, $summary['study_program']);
        $this->assertSame($program->department->faculty->name, $summary['faculty']);
        $this->assertSame('2004-05-04', $summary['tanggal_lahir']);
    }

    public function test_magang_ska_and_pln_mahasiswa_read_endpoints_expose_profile_summary_without_losing_existing_keys(): void
    {
        [$student, $program] = $this->canonicalStudent();

        foreach ([
            '/api/mahasiswa/surat-pengantar-magang/draft',
            '/api/mahasiswa/surat-keterangan-aktif/draft',
            '/api/mahasiswa/proses-luar-negeri/draft',
        ] as $endpoint) {
            $response = $this->actingAs($student, 'sanctum')->getJson($endpoint);

            $response->assertOk()->assertJsonStructure(['user', 'profile', 'application', 'profile_summary']);
            $this->assertCanonicalSummary($response, $program);
        }

        $detailEndpoints = [
            '/api/mahasiswa/surat-pengantar-magang/' . $this->magangApplication($student)->id,
            '/api/mahasiswa/surat-keterangan-aktif/' . $this->aktifApplication($student)->id,
            '/api/mahasiswa/proses-luar-negeri/' . $this->prosesLuarNegeriApplication($student)->id,
        ];

        foreach ($detailEndpoints as $endpoint) {
            $response = $this->actingAs($student, 'sanctum')->getJson($endpoint);

            $response->assertOk()->assertJsonStructure(['application', 'profile_summary']);
            $this->assertCanonicalSummary($response, $program);
        }
    }

    public function test_beasiswa_and_reviewer_detail_endpoints_expose_same_profile_summary_additively(): void
    {
        [$student, $program] = $this->canonicalStudent();
        $beasiswa = $this->scholarshipApplication($student);

        $stepOne = $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-permohonan-beasiswa/step-1');
        $stepOne->assertOk()->assertJsonStructure(['user', 'student', 'application', 'profile_summary']);
        $this->assertCanonicalSummary($stepOne, $program);

        $detail = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$beasiswa->id}");
        $detail->assertOk()->assertJsonStructure([
            'application' => ['student', 'normalized_student', 'mahasiswa_profile'],
            'profile_summary',
        ]);
        $this->assertCanonicalSummary($detail, $program);

        $tendik = $this->tendikPersuratan([
            ScholarshipApplication::LETTER_TYPE,
            SuratPengantarMagangApplication::LETTER_TYPE,
            SuratKeteranganAktifApplication::LETTER_TYPE,
            ProsesLuarNegeriApplication::LETTER_TYPE,
        ]);
        $reviewEndpoints = [
            "/api/tendik/surat-permohonan-beasiswa/{$beasiswa->id}",
            '/api/tendik/surat-pengantar-magang/' . $this->magangApplication($student)->id,
            '/api/tendik/surat-keterangan-aktif/' . $this->aktifApplication($student)->id,
            '/api/tendik/proses-luar-negeri/' . $this->prosesLuarNegeriApplication($student)->id,
        ];

        foreach ($reviewEndpoints as $endpoint) {
            $response = $this->actingAs($tendik, 'sanctum')->getJson($endpoint);

            $response->assertOk()->assertJsonStructure(['application', 'profile_summary']);
            $this->assertCanonicalSummary($response, $program);
        }

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $program->id]);
        $akademik = $this->actingAs($kaprodi, 'sanctum')
            ->getJson("/api/akademik/surat-permohonan-beasiswa/{$beasiswa->id}");
        $akademik->assertOk()->assertJsonStructure(['application', 'student', 'profile_summary']);
        $this->assertCanonicalSummary($akademik, $program);
    }

    /**
     * @return array{0: \App\Models\User, 1: StudyProgram}
     */
    private function canonicalStudent(): array
    {
        $department = $this->department([
            'code' => 'DTEDI',
            'name' => 'Departemen Teknik Elektro dan Informatika',
        ]);
        $program = $this->studyProgram($department, [
            'code' => 'TRPL',
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
        ]);
        [$student] = $this->completeMahasiswa([
            'name' => 'Endpoint Student',
            'email' => 'endpoint.student@example.test',
        ], [
            'nim' => '22/493038/SV/20654',
            'tempat_lahir' => 'Sleman',
            'tanggal_lahir' => '2004-05-04',
            'jenis_kelamin' => 'L',
        ], $program);

        return [$student, $program];
    }

    private function assertCanonicalSummary(TestResponse $response, StudyProgram $program): void
    {
        $response->assertJsonPath('profile_summary.full_name', 'Endpoint Student');
        $response->assertJsonPath('profile_summary.nim', '22/493038/SV/20654');
        $response->assertJsonPath('profile_summary.email', 'endpoint.student@example.test');
        $response->assertJsonPath('profile_summary.faculty', $program->department->faculty->name);
        $response->assertJsonPath('profile_summary.study_program', $program->name);
        $response->assertJsonPath('profile_summary.study_program_code', $program->code);
        $response->assertJsonPath('profile_summary.department', $program->department->name);
        $response->assertJsonPath('profile_summary.tanggal_lahir', '2004-05-04');
    }
}
