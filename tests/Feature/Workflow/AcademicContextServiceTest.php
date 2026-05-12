<?php

namespace Tests\Feature\Workflow;

use App\Enums\UserStatus;
use App\Services\AcademicContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicContextServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_resolves_kaprodi_sekprodi_kadep_sekdep_from_users_table(): void
    {
        $department = $this->department();
        $program = $this->studyProgram($department);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $program->id]);
        $sekprodi = $this->akademik('sekprodi', ['study_program_id' => $program->id]);
        $kadep = $this->akademik('kadep', ['department_id' => $department->id]);
        $sekdep = $this->akademik('sekdep', ['department_id' => $department->id]);

        $service = app(AcademicContextService::class);

        $this->assertTrue($kaprodi->is($service->currentKaprodiForStudyProgram($program->id)));
        $this->assertTrue($sekprodi->is($service->currentSekprodiForStudyProgram($program->id)));
        $this->assertTrue($kadep->is($service->currentKadepForDepartment($department->id)));
        $this->assertTrue($sekdep->is($service->currentSekdepForDepartment($department->id)));
    }

    public function test_resolves_active_user_by_sub_role_and_scope(): void
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $program->id]);
        $kadep = $this->akademik('kadep', ['department_id' => $department->id]);

        $service = app(AcademicContextService::class);

        $this->assertTrue($kaprodi->is($service->currentKaprodiForStudyProgram((string) $program->id)));
        $this->assertTrue($kadep->is($service->currentKadepForDepartment((string) $department->id)));
    }

    public function test_suspended_user_is_not_resolved(): void
    {
        $department = $this->department();
        $activeKadep = $this->akademik('kadep', ['department_id' => $department->id]);
        $suspendedKadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'status' => UserStatus::Suspended,
        ]);

        $resolved = app(AcademicContextService::class)->currentKadepForDepartment($department->id);

        $this->assertTrue($activeKadep->is($resolved));
        $this->assertFalse($suspendedKadep->is($resolved));
    }

    public function test_wrong_scope_is_not_resolved(): void
    {
        $department = $this->department();
        $otherDepartment = $this->department(['code' => 'DTM', 'name' => 'Departemen Teknik Mesin']);

        $this->akademik('kadep', ['department_id' => $department->id]);

        $this->assertNull(app(AcademicContextService::class)->currentKadepForDepartment($otherDepartment->id));
        $this->assertNotNull(app(AcademicContextService::class)->currentKadepForDepartment($department->id));
    }
}
