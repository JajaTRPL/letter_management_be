<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BeasiswaSubmitDeclarationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_submit_is_rejected_without_declaration_payload(): void
    {
        Notification::fake();
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('declaration_accepted');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
        ]);
    }

    public function test_submit_is_rejected_with_unchecked_declaration(): void
    {
        Notification::fake();
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [
                'declaration_accepted' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('declaration_accepted');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
        ]);
    }

    public function test_submit_succeeds_with_accepted_declaration(): void
    {
        Notification::fake();
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_DRAFT,
            'submitted_at' => null,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-permohonan-beasiswa/submit', [
                'declaration_accepted' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);
    }
}
