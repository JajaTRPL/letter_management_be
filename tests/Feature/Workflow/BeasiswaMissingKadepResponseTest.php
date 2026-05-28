<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BeasiswaMissingKadepResponseTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_kadep_approve_returns_422_when_no_official_kadep_active(): void
    {
        Notification::fake();
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        $department = $program->department;
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
        ]);

        // Sekdep approves as Kadep stage actor, but no official Kadep exists.
        $sekdepActor = $this->akademik('sekdep', ['department_id' => $department->id]);

        $response = $this->actingAs($sekdepActor, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve");

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'missing_official_kadep');
        $this->assertStringContainsString('Ketua Departemen', (string) $response->json('message'));

        // Status must remain unchanged — pre-flight guard, no transaction opened.
        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'kadep_approved_by' => null,
            'kadep_approved_at' => null,
        ]);
    }

    public function test_kadep_pre_flight_does_not_block_kaprodi_stage_approval(): void
    {
        Notification::fake();
        Storage::fake('public');

        $program = $this->defaultStudyProgram();
        [$student] = $this->completeMahasiswa([], [], $program);
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);

        $sekprodi = $this->akademik('sekprodi', ['study_program_id' => $program->id]);

        // No Kadep configured anywhere — Kaprodi-stage approval should still succeed.
        $this->mockBeasiswaPreviewGenerationForProdiApprove();

        $this->actingAs($sekprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'kaprodi_approved_by' => $sekprodi->id,
        ]);
    }
}
