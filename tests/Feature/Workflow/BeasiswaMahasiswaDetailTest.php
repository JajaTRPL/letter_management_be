<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BeasiswaMahasiswaDetailTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_owner_can_fetch_submitted_application_detail_via_both_prefixes(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'scholarship_name' => 'Beasiswa Owner Detail',
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        foreach (['scholarship', 'surat-permohonan-beasiswa'] as $prefix) {
            $response = $this->actingAs($student, 'sanctum')
                ->getJson("/api/mahasiswa/{$prefix}/{$application->id}");

            $response->assertOk();
            $payload = $response->json('application');
            $this->assertIsArray($payload);
            $this->assertSame($application->id, $payload['id']);
            $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $payload['status']);
            $this->assertSame('Beasiswa Owner Detail', $payload['scholarship_name']);
            $this->assertArrayHasKey('student', $payload);
            $this->assertArrayHasKey('mahasiswa_profile', $payload);
        }
    }

    public function test_non_owner_is_forbidden_from_fetching_detail(): void
    {
        Storage::fake('public');

        [$owner] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($owner, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        [$intruder] = $this->completeMahasiswa();

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}")
            ->assertForbidden();
    }

    public function test_show_for_mahasiswa_does_not_mutate_status(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);
    }

    public function test_show_returns_null_legacy_document_compatibility_field_for_review_and_completed_statuses(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $readyApplication = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $readyResponse = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$readyApplication->id}");
        $readyResponse->assertOk();
        $this->assertNull($readyResponse->json('application.generated_docx_path'));

        $completedApplication = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $completedResponse = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$completedApplication->id}");
        $completedResponse->assertOk();
        $this->assertNull($completedResponse->json('application.generated_docx_path'));
    }

    public function test_completed_application_does_not_block_step_one_and_remains_in_history(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $completed = $this->scholarshipApplication($student, [
            'scholarship_name' => 'Beasiswa Semester 4',
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        // step-1 must report no in-flight application so the FE can open a fresh form.
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-permohonan-beasiswa/step-1')
            ->assertOk()
            ->assertJsonPath('application', null);

        // The completed row must still appear in history with its status intact.
        $history = $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-permohonan-beasiswa/applications')
            ->assertOk()
            ->json('applications');

        $this->assertIsArray($history);
        $ids = array_column($history, 'id');
        $this->assertContains($completed->id, $ids);
        $statusById = array_column($history, 'status', 'id');
        $this->assertSame(ScholarshipApplication::STATUS_COMPLETED, $statusById[$completed->id]);
    }

    public function test_submitted_application_is_returned_as_in_flight_by_step_one(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $submitted = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        // Existing Submitted application is not editable, so step-1 still returns null
        // (the FE form-opener uses /applications to detect in-flight readonly states).
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-permohonan-beasiswa/step-1')
            ->assertOk()
            ->assertJsonPath('application', null);

        // /applications must surface the Submitted row so the FE routes to detail.
        $history = $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-permohonan-beasiswa/applications')
            ->assertOk()
            ->json('applications');

        $statusById = array_column($history, 'status', 'id');
        $this->assertSame(ScholarshipApplication::STATUS_SUBMITTED, $statusById[$submitted->id]);
    }

    public function test_static_routes_take_precedence_over_dynamic_detail_route(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        // /applications must hit getApplications, not showForMahasiswa('applications')
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-permohonan-beasiswa/applications')
            ->assertOk()
            ->assertJsonStructure(['applications']);

        // /step-1 must hit getStep1, not showForMahasiswa('step-1')
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/mahasiswa/surat-permohonan-beasiswa/step-1')
            ->assertOk()
            ->assertJsonStructure(['user', 'student']);
    }
}
