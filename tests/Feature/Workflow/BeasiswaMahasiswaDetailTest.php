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

    public function test_show_redacts_generated_docx_path_until_completed(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $readyApplication = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_docx_path' => 'scholarships/sample.docx',
        ]);

        $readyResponse = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$readyApplication->id}");
        $readyResponse->assertOk();
        $this->assertNull($readyResponse->json('application.generated_docx_path'));

        $completedApplication = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_COMPLETED,
            'generated_docx_path' => 'scholarships/final.docx',
            'completed_at' => now(),
        ]);

        $completedResponse = $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$completedApplication->id}");
        $completedResponse->assertOk();
        $this->assertSame('scholarships/final.docx', $completedResponse->json('application.generated_docx_path'));
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
