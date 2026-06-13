<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Services\LetterAttachmentAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LetterAttachmentAuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private LetterAttachmentAuthorizationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(LetterAttachmentAuthorizationService::class);
    }

    public function test_owner_super_admin_and_assigned_persuratan_tendik_are_allowed(): void
    {
        $application = $this->scholarshipApplication();

        $this->assertTrue($this->service->canPreview($application->user, $application, ScholarshipApplication::LETTER_TYPE));
        $this->assertTrue($this->service->canPreview($this->primarySuperAdmin(), $application, ScholarshipApplication::LETTER_TYPE));
        $this->assertTrue($this->service->canPreview(
            $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]),
            $application,
            ScholarshipApplication::LETTER_TYPE,
        ));
    }

    public function test_non_owner_unassigned_and_non_persuratan_users_are_denied(): void
    {
        $application = $this->scholarshipApplication();
        [$intruder] = $this->completeMahasiswa();

        $this->assertFalse($this->service->canPreview($intruder, $application, ScholarshipApplication::LETTER_TYPE));
        $this->assertFalse($this->service->canPreview(
            $this->tendikPersuratan([\App\Models\SuratPengantarMagangApplication::LETTER_TYPE]),
            $application,
            ScholarshipApplication::LETTER_TYPE,
        ));
        $this->assertFalse($this->service->canPreview($this->tendikSarpras(), $application, ScholarshipApplication::LETTER_TYPE));
    }

    public function test_matching_academic_scopes_are_allowed_and_wrong_scopes_are_denied(): void
    {
        $program = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student);

        $this->assertTrue($this->service->canPreview(
            $this->akademik('kaprodi', ['study_program_id' => $program->id]),
            $application,
            ScholarshipApplication::LETTER_TYPE,
        ));
        $this->assertTrue($this->service->canPreview(
            $this->akademik('kadep', ['department_id' => $program->department_id]),
            $application,
            ScholarshipApplication::LETTER_TYPE,
        ));

        $this->assertFalse($this->service->canPreview(
            $this->akademik('kaprodi', ['study_program_id' => $this->studyProgram()->id]),
            $application,
            ScholarshipApplication::LETTER_TYPE,
        ));
        $this->assertFalse($this->service->canPreview(
            $this->akademik('kadep', ['department_id' => $this->department()->id]),
            $application,
            ScholarshipApplication::LETTER_TYPE,
        ));
    }

    public function test_unknown_letter_type_and_unsaved_application_are_denied(): void
    {
        $application = $this->scholarshipApplication();

        $this->assertFalse($this->service->canPreview($application->user, $application, 'unknown'));
        $this->assertFalse($this->service->canPreview(
            $application->user,
            new ScholarshipApplication(['user_id' => $application->user_id]),
            ScholarshipApplication::LETTER_TYPE,
        ));
    }
}
