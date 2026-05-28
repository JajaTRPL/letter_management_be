<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Services\AcademicSignatoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class BeasiswaApproverTrackingTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_beasiswa_approval_stores_actual_prodi_and_department_actors(): void
    {
        Notification::fake();

        $program = $this->defaultStudyProgram();
        $department = $program->department;
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student);

        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $sekprodiActor = $this->akademik('sekprodi', ['study_program_id' => $program->id]);
        $this->akademik('kaprodi', ['study_program_id' => $program->id]);
        $sekdepActor = $this->akademik('sekdep', ['department_id' => $department->id]);
        $this->akademik('kadep', ['department_id' => $department->id]);

        $this->mockBeasiswaPreviewGenerationForApprove();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve", [
                'nomor_surat' => 'BEA-TRACK-001',
            ])
            ->assertOk();

        $this->mockBeasiswaPreviewGenerationForProdiApprove();

        $this->actingAs($sekprodiActor, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'kaprodi_approved_by' => $sekprodiActor->id,
        ]);

        $this->mockBeasiswaPreviewGenerationForDepartmentApprove();

        $this->actingAs($sekdepActor, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'kadep_approved_by' => $sekdepActor->id,
        ]);
    }

    public function test_official_signatory_resolution_uses_kaprodi_and_kadep_role_holders(): void
    {
        $department = $this->department();
        $program = $this->studyProgram($department);
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student);

        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $program->id]);
        $sekprodi = $this->akademik('sekprodi', ['study_program_id' => $program->id]);
        $kadep = $this->akademik('kadep', [
            'department_id' => $department->id,
            'nip' => '197512122005011002',
            'signature_path' => '/storage/signatures/kadep.png',
        ]);
        $sekdep = $this->akademik('sekdep', ['department_id' => $department->id]);

        $service = app(AcademicSignatoryService::class);

        $this->assertTrue($kaprodi->is($service->officialKaprodiForApplication($application)));
        $this->assertTrue($sekprodi->is($service->officialSekprodiForApplication($application)));
        $this->assertTrue($kadep->is($service->officialKadepForApplication($application)));
        $this->assertTrue($sekdep->is($service->officialSekdepForApplication($application)));
        $this->assertSame('197512122005011002', $service->nipLikeValue($kadep));
        $this->assertSame('-', $service->nipLikeValue($kaprodi));
        $this->assertSame('/storage/signatures/kadep.png', $service->signaturePath($kadep));
    }

}
