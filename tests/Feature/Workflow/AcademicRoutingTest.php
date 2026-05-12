<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicRoutingTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_akademik_dashboard_stats_use_uncapped_scoped_counts(): void
    {
        [, , $trpl, , $otherProgram] = $this->academicRoutingFixtures();

        [$trplStudent] = $this->completeMahasiswa([], [], $trpl);
        [$otherStudent] = $this->completeMahasiswa([], [], $otherProgram);

        for ($i = 0; $i < 101; $i++) {
            $this->scholarshipApplication($trplStudent, [
                'scholarship_name' => "Beasiswa {$i}",
                'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            ]);
            $this->magangApplication($trplStudent, [
                'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            ]);
        }

        $otherBeasiswa = $this->scholarshipApplication($otherStudent, [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);

        $kaprodiTrpl = $this->akademik('kaprodi', ['study_program_id' => $trpl->id]);

        $response = $this->actingAs($kaprodiTrpl, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk()
            ->assertJsonStructure([
                'stats' => ['total_incoming', 'needs_verification', 'finished_this_month'],
                'tasks',
                'meta' => ['displayed_tasks', 'total_matching_tasks', 'is_limited', 'limit', 'per_type_limit', 'limit_scope'],
            ])
            ->assertJsonCount(200, 'tasks')
            ->assertJsonPath('stats.total_incoming', 202)
            ->assertJsonPath('stats.needs_verification', 202)
            ->assertJsonPath('meta.displayed_tasks', 200)
            ->assertJsonPath('meta.total_matching_tasks', 202)
            ->assertJsonPath('meta.is_limited', true)
            ->assertJsonPath('meta.limit', 100)
            ->assertJsonPath('meta.per_type_limit', 100)
            ->assertJsonPath('meta.limit_scope', 'per_letter_type');

        $tasks = $response->json('tasks');

        $this->assertCount(100, collect($tasks)->where('letter_type', ScholarshipApplication::LETTER_TYPE));
        $this->assertCount(100, collect($tasks)->where('letter_type', SuratPengantarMagangApplication::LETTER_TYPE));
        $this->assertTaskMissing($tasks, ScholarshipApplication::LETTER_TYPE, $otherBeasiswa->id);
    }

    public function test_akademik_dashboard_meta_is_not_limited_when_all_scoped_tasks_are_displayed(): void
    {
        [, , $trpl] = $this->academicRoutingFixtures();

        [$trplStudent] = $this->completeMahasiswa([], [], $trpl);

        $this->scholarshipApplication($trplStudent, [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);
        $this->magangApplication($trplStudent, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
        ]);

        $kaprodiTrpl = $this->akademik('kaprodi', ['study_program_id' => $trpl->id]);

        $this->actingAs($kaprodiTrpl, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk()
            ->assertJsonCount(2, 'tasks')
            ->assertJsonPath('stats.total_incoming', 2)
            ->assertJsonPath('stats.needs_verification', 2)
            ->assertJsonPath('meta.displayed_tasks', 2)
            ->assertJsonPath('meta.total_matching_tasks', 2)
            ->assertJsonPath('meta.is_limited', false)
            ->assertJsonPath('meta.limit', 100)
            ->assertJsonPath('meta.per_type_limit', 100)
            ->assertJsonPath('meta.limit_scope', 'per_letter_type');
    }

    public function test_akademik_task_feed_is_scoped_by_student_prodi_and_department(): void
    {
        [$dtedi, $otherDepartment, $trpl, $tre, $otherProgram] = $this->academicRoutingFixtures();

        [$trplStudent] = $this->completeMahasiswa([], [], $trpl);
        [$treStudent] = $this->completeMahasiswa([], [], $tre);
        [$otherStudent] = $this->completeMahasiswa([], [], $otherProgram);

        $trplBeasiswa = $this->scholarshipApplication($trplStudent, [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);
        $treMagang = $this->magangApplication($treStudent, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
        ]);
        $otherAktif = $this->aktifApplication($otherStudent, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
        ]);

        $kaprodiTrpl = $this->akademik('kaprodi', ['study_program_id' => $trpl->id]);
        $kaprodiTre = $this->akademik('kaprodi', ['study_program_id' => $tre->id]);
        $sekprodiTrpl = $this->akademik('sekprodi', ['study_program_id' => $trpl->id]);

        $kaprodiTrplTasks = $this->actingAs($kaprodiTrpl, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertTaskPresent($kaprodiTrplTasks, ScholarshipApplication::LETTER_TYPE, $trplBeasiswa->id);
        $this->assertTaskMissing($kaprodiTrplTasks, SuratPengantarMagangApplication::LETTER_TYPE, $treMagang->id);
        $this->assertTaskMissing($kaprodiTrplTasks, SuratKeteranganAktifApplication::LETTER_TYPE, $otherAktif->id);

        $kaprodiTreTasks = $this->actingAs($kaprodiTre, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertTaskPresent($kaprodiTreTasks, SuratPengantarMagangApplication::LETTER_TYPE, $treMagang->id);
        $this->assertTaskMissing($kaprodiTreTasks, ScholarshipApplication::LETTER_TYPE, $trplBeasiswa->id);

        $sekprodiTrplTasks = $this->actingAs($sekprodiTrpl, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertTaskPresent($sekprodiTrplTasks, ScholarshipApplication::LETTER_TYPE, $trplBeasiswa->id);
        $this->assertTaskMissing($sekprodiTrplTasks, SuratPengantarMagangApplication::LETTER_TYPE, $treMagang->id);

        $trplAktif = $this->aktifApplication($trplStudent, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
        ]);
        $treProsesLuarNegeri = $this->prosesLuarNegeriApplication($treStudent, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
        ]);
        $otherBeasiswa = $this->scholarshipApplication($otherStudent, [
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $kadepDtedi = $this->akademik('kadep', ['department_id' => $dtedi->id]);
        $kadepOtherDepartment = $this->akademik('kadep', ['department_id' => $otherDepartment->id]);

        $kadepDtediTasks = $this->actingAs($kadepDtedi, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertTaskPresent($kadepDtediTasks, SuratKeteranganAktifApplication::LETTER_TYPE, $trplAktif->id);
        $this->assertTaskPresent($kadepDtediTasks, ProsesLuarNegeriApplication::LETTER_TYPE, $treProsesLuarNegeri->id);
        $this->assertTaskMissing($kadepDtediTasks, ScholarshipApplication::LETTER_TYPE, $otherBeasiswa->id);

        $otherDepartmentTasks = $this->actingAs($kadepOtherDepartment, 'sanctum')
            ->getJson('/api/akademik/dashboard/tasks')
            ->assertOk()
            ->json('tasks');

        $this->assertTaskPresent($otherDepartmentTasks, ScholarshipApplication::LETTER_TYPE, $otherBeasiswa->id);
        $this->assertTaskMissing($otherDepartmentTasks, SuratKeteranganAktifApplication::LETTER_TYPE, $trplAktif->id);
        $this->assertTaskMissing($otherDepartmentTasks, ProsesLuarNegeriApplication::LETTER_TYPE, $treProsesLuarNegeri->id);
    }

    private function academicRoutingFixtures(): array
    {
        $dtedi = $this->department([
            'code' => 'DTEDI',
            'name' => 'Departemen Teknik Elektro dan Informatika',
        ]);

        $otherDepartment = $this->department([
            'code' => 'DLAIN',
            'name' => 'Departemen Lain',
        ]);

        $trpl = $this->studyProgram($dtedi, [
            'code' => 'TRPL',
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
        ]);

        $tre = $this->studyProgram($dtedi, [
            'code' => 'TRE',
            'name' => 'Teknologi Rekayasa Elektro',
        ]);

        $otherProgram = $this->studyProgram($otherDepartment, [
            'code' => 'TRO',
            'name' => 'Teknologi Rekayasa Lain',
        ]);

        return [$dtedi, $otherDepartment, $trpl, $tre, $otherProgram];
    }

    private function assertTaskPresent(array $tasks, string $letterType, int $id): void
    {
        $this->assertTrue(
            collect($tasks)->contains(fn (array $row): bool => $row['letter_type'] === $letterType && $row['id'] === $id),
            "Expected {$letterType} task {$id} to be present."
        );
    }

    private function assertTaskMissing(array $tasks, string $letterType, int $id): void
    {
        $this->assertFalse(
            collect($tasks)->contains(fn (array $row): bool => $row['letter_type'] === $letterType && $row['id'] === $id),
            "Expected {$letterType} task {$id} to be absent."
        );
    }
}
