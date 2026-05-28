<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Negative-path access coverage for Magang. Pins the current contract:
 *   - Mahasiswa ownership required for detail/generated-preview/complete.
 *   - Tendik must hold the Magang letter type in assigned_tasks.
 *   - Akademik scope is prodi for STATUS_APPROVED_TENDIK and
 *     department for STATUS_APPROVED_KAPRODI.
 *   - /api/storage/surat-pengantar-magang/generated/* is hard-blocked
 *     for every authenticated role, regardless of ownership.
 */
class SuratPengantarMagangAccessControlTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    // --------------------------------------------------------------
    // Mahasiswa ownership
    // --------------------------------------------------------------

    public function test_non_owner_mahasiswa_cannot_view_magang_detail(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$intruder] = $this->completeMahasiswa();
        $application = $this->magangApplication($owner);

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/mahasiswa/surat-pengantar-magang/{$application->id}")
            ->assertForbidden();
    }

    public function test_non_owner_mahasiswa_cannot_stream_generated_preview_magang(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$intruder] = $this->completeMahasiswa();
        $application = $this->magangApplication($owner, [
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->actingAs($intruder, 'sanctum')
            ->get("/api/mahasiswa/surat-pengantar-magang/{$application->id}/generated-preview")
            ->assertForbidden();
    }

    public function test_non_owner_mahasiswa_cannot_complete_magang(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$intruder] = $this->completeMahasiswa();
        $application = $this->magangApplication($owner, [
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/mahasiswa/surat-pengantar-magang/{$application->id}/complete")
            ->assertForbidden();

        $this->assertSame(
            SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            $application->fresh()->status,
        );
    }

    // --------------------------------------------------------------
    // Tendik strict assignment
    // --------------------------------------------------------------

    public function test_tendik_without_magang_assignment_cannot_view_magang_detail(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->magangApplication();

        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-pengantar-magang/{$application->id}")
            ->assertForbidden();
    }

    public function test_tendik_without_magang_assignment_cannot_approve_magang(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat' => 'SHOULD-NOT-APPLY',
            ])
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->tendik_approved_by);
        $this->assertNull($fresh->nomor_surat);
    }

    public function test_tendik_without_magang_assignment_cannot_revise_or_reject_magang(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->magangApplication(null, [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/revise", [
                'note' => 'should be denied',
            ])
            ->assertForbidden();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/reject", [
                'reason' => 'should be denied',
            ])
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->revised_by);
        $this->assertNull($fresh->rejected_by);
    }

    public function test_assigned_magang_tendik_can_view_detail(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication();

        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/surat-pengantar-magang/{$application->id}")
            ->assertOk();
    }

    // --------------------------------------------------------------
    // Akademik prodi/department scope
    // --------------------------------------------------------------

    public function test_akademik_wrong_prodi_cannot_approve_magang_at_prodi_stage(): void
    {
        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);

        $otherDept = $this->department();
        $otherProgram = $this->studyProgram($otherDept);
        $wrongKaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $otherProgram->id,
            'department_id' => $otherDept->id,
        ]);

        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'MAG-AC-001',
        ]);

        $this->actingAs($wrongKaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertNull($fresh->kaprodi_approved_by);
    }

    public function test_akademik_wrong_department_cannot_approve_magang_at_department_stage(): void
    {
        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);

        $otherDept = $this->department();
        $wrongKadep = $this->akademik('kadep', ['department_id' => $otherDept->id]);

        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'MAG-AC-002',
        ]);

        $this->actingAs($wrongKadep, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertNull($fresh->kadep_approved_by);
    }

    public function test_same_prodi_kaprodi_can_view_magang_detail_at_prodi_stage(): void
    {
        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);
        $kaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $studentProgram->id,
            'department_id' => $studentDept->id,
        ]);
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->getJson("/api/akademik/surat-pengantar-magang/{$application->id}")
            ->assertOk();
    }

    public function test_same_department_kadep_can_view_magang_detail_at_department_stage(): void
    {
        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);
        $kadep = $this->akademik('kadep', ['department_id' => $studentDept->id]);
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->getJson("/api/akademik/surat-pengantar-magang/{$application->id}")
            ->assertOk();
    }

    // --------------------------------------------------------------
    // Storage gate — /api/storage/surat-pengantar-magang/generated/*
    // --------------------------------------------------------------

    public function test_magang_generated_storage_path_is_blocked_for_owner_mahasiswa(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF');
        [$student] = $this->completeMahasiswa();
        $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_COMPLETED,
        ]);

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/surat-pengantar-magang/generated/final.pdf')
            ->assertForbidden();
    }

    public function test_magang_generated_storage_path_is_blocked_for_assigned_tendik(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF');
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->get('/api/storage/surat-pengantar-magang/generated/final.pdf')
            ->assertForbidden();
    }

    public function test_magang_generated_storage_path_is_blocked_for_akademik(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF');
        $kaprodi = $this->akademik('kaprodi');

        $this->actingAs($kaprodi, 'sanctum')
            ->get('/api/storage/surat-pengantar-magang/generated/final.pdf')
            ->assertForbidden();
    }

    public function test_magang_generated_storage_path_is_blocked_unauthenticated(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF');

        $this->getJson('/api/storage/surat-pengantar-magang/generated/final.pdf')
            ->assertUnauthorized();
    }
}
