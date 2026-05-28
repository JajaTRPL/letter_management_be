<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Negative-path access coverage for PLN. Confirms today's contract:
 *   - Mahasiswa ownership is required for detail/complete.
 *   - Tendik must hold the PLN letter type in assigned_tasks.
 *   - Akademik scope is prodi for STATUS_APPROVED_TENDIK and
 *     department for STATUS_APPROVED_KAPRODI.
 *   - /api/storage/proses-luar-negeri/generated/* is hard-blocked
 *     for every authenticated role, regardless of ownership.
 *
 * These tests do not change auth logic; they pin the current contract so
 * later standardization phases cannot silently regress it.
 */
class ProsesLuarNegeriAccessControlTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    // --------------------------------------------------------------
    // Mahasiswa ownership
    // --------------------------------------------------------------

    public function test_non_owner_mahasiswa_cannot_view_pln_detail(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$intruder] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($owner);

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$application->id}")
            ->assertForbidden();
    }

    public function test_pln_legacy_preview_route_is_retired(): void
    {
        Storage::fake('public');
        [$owner] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($owner, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/preview")
            ->assertNotFound();
    }

    public function test_non_owner_mahasiswa_cannot_complete_pln(): void
    {
        [$owner] = $this->completeMahasiswa();
        [$intruder] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($owner, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertForbidden();

        $this->assertSame(
            ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            $application->fresh()->status,
        );
    }

    // --------------------------------------------------------------
    // Tendik strict assignment
    // --------------------------------------------------------------

    public function test_tendik_without_pln_assignment_cannot_view_pln_detail(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication();

        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/proses-luar-negeri/{$application->id}")
            ->assertForbidden();
    }

    public function test_tendik_without_pln_assignment_cannot_approve_pln(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/approve", [
                'nomor_surat' => 'SHOULD-NOT-APPLY',
            ])
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->tendik_approved_by);
        $this->assertNull($fresh->nomor_surat);
    }

    public function test_tendik_without_pln_assignment_cannot_revise_or_reject_pln(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
        ]);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/revise", [
                'note' => 'should be denied',
            ])
            ->assertForbidden();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/reject", [
                'reason' => 'should be denied',
            ])
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->revised_by);
        $this->assertNull($fresh->rejected_by);
    }

    public function test_assigned_pln_tendik_can_view_detail(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication();

        $this->actingAs($tendik, 'sanctum')
            ->getJson("/api/tendik/proses-luar-negeri/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.generated_pdf_path', null);
    }

    // --------------------------------------------------------------
    // Akademik prodi/department scope
    // --------------------------------------------------------------

    public function test_akademik_wrong_prodi_cannot_approve_pln_at_prodi_stage(): void
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

        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'PLN-AC-001',
        ]);

        $this->actingAs($wrongKaprodi, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertNull($fresh->kaprodi_approved_by);
    }

    public function test_akademik_wrong_department_cannot_approve_pln_at_department_stage(): void
    {
        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);

        $otherDept = $this->department();
        $wrongKadep = $this->akademik('kadep', ['department_id' => $otherDept->id]);

        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'PLN-AC-002',
        ]);

        $this->actingAs($wrongKadep, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertForbidden();

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertNull($fresh->kadep_approved_by);
    }

    public function test_same_prodi_kaprodi_can_view_pln_detail_at_prodi_stage(): void
    {
        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);
        $kaprodi = $this->akademik('kaprodi', [
            'study_program_id' => $studentProgram->id,
            'department_id' => $studentDept->id,
        ]);
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->getJson("/api/akademik/proses-luar-negeri/{$application->id}")
            ->assertOk();
    }

    public function test_same_department_kadep_can_view_pln_detail_at_department_stage(): void
    {
        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$student] = $this->completeMahasiswa([], [], $studentProgram);
        $kadep = $this->akademik('kadep', ['department_id' => $studentDept->id]);
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->actingAs($kadep, 'sanctum')
            ->getJson("/api/akademik/proses-luar-negeri/{$application->id}")
            ->assertOk();
    }

    // --------------------------------------------------------------
    // Storage gate — /api/storage/proses-luar-negeri/generated/*
    // --------------------------------------------------------------

    public function test_pln_generated_storage_path_is_blocked_for_owner_mahasiswa(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('proses-luar-negeri/generated/final.pdf', '%PDF');
        [$student] = $this->completeMahasiswa();
        $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_COMPLETED,
        ]);

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/proses-luar-negeri/generated/final.pdf')
            ->assertForbidden();
    }

    public function test_pln_generated_storage_path_is_blocked_for_assigned_tendik(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('proses-luar-negeri/generated/final.pdf', '%PDF');
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->get('/api/storage/proses-luar-negeri/generated/final.pdf')
            ->assertForbidden();
    }

    public function test_pln_generated_storage_path_is_blocked_for_akademik(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('proses-luar-negeri/generated/final.pdf', '%PDF');
        $kaprodi = $this->akademik('kaprodi');

        $this->actingAs($kaprodi, 'sanctum')
            ->get('/api/storage/proses-luar-negeri/generated/final.pdf')
            ->assertForbidden();
    }

    public function test_pln_generated_storage_path_is_blocked_unauthenticated(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('proses-luar-negeri/generated/final.pdf', '%PDF');

        $this->getJson('/api/storage/proses-luar-negeri/generated/final.pdf')
            ->assertUnauthorized();
    }
}
