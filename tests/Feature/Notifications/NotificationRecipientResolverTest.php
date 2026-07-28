<?php

namespace Tests\Feature\Notifications;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\StudyProgram;
use App\Services\Notifications\NotificationRecipientResolver;
use Tests\Feature\Peminjaman\RoomBookingApiTestCase;

/**
 * Recipient scope for the letter / academic / persuratan / superadmin families.
 * These prove the resolver never broadcasts to a whole role and obeys prodi,
 * department, and assignment scope — the same guarantees the wired room-booking
 * families already enforce end-to-end elsewhere.
 */
class NotificationRecipientResolverTest extends RoomBookingApiTestCase
{
    private NotificationRecipientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(NotificationRecipientResolver::class);
    }

    public function test_persuratan_prefers_the_assigned_officer_over_the_pool(): void
    {
        $assigned = $this->persuratan();
        $this->persuratan(); // another officer in the pool
        $this->persuratan();

        $recipients = $this->resolver->letterPersuratan($assigned->id);

        $this->assertSame([$assigned->id], $recipients->pluck('id')->all());
    }

    public function test_persuratan_falls_back_to_the_active_pool_when_unassigned(): void
    {
        $a = $this->persuratan();
        $b = $this->persuratan();
        $this->persuratan(['status' => UserStatus::Suspended]);

        $recipients = $this->resolver->letterPersuratan(null);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $recipients->pluck('id')->all());
    }

    public function test_prodi_stage_targets_only_matching_study_program_approvers(): void
    {
        $dept = $this->department('DEPT');
        $prodiA = $this->studyProgram('TRPL', $dept->id);
        $prodiB = $this->studyProgram('TRI', $dept->id);
        $kaprodiA = $this->akademik('kaprodi', studyProgramId: $prodiA->id);
        $sekprodiA = $this->akademik('sekprodi', studyProgramId: $prodiA->id);
        $kaprodiB = $this->akademik('kaprodi', studyProgramId: $prodiB->id);
        $kadep = $this->akademik('kadep', departmentId: $dept->id);

        $recipients = $this->resolver->academicApprovers(['kaprodi', 'sekprodi'], studyProgramId: $prodiA->id, departmentId: null);

        $this->assertEqualsCanonicalizing([$kaprodiA->id, $sekprodiA->id], $recipients->pluck('id')->all());
        $ids = $recipients->pluck('id')->all();
        $this->assertNotContains($kaprodiB->id, $ids);
        $this->assertNotContains($kadep->id, $ids);
    }

    public function test_department_stage_targets_only_matching_department_approvers(): void
    {
        $deptA = $this->department('DA');
        $deptB = $this->department('DB');
        $kadepA = $this->akademik('kadep', departmentId: $deptA->id);
        $sekdepA = $this->akademik('sekdep', departmentId: $deptA->id);
        $kadepB = $this->akademik('kadep', departmentId: $deptB->id);

        $recipients = $this->resolver->academicApprovers(['kadep', 'sekdep'], studyProgramId: null, departmentId: $deptA->id);

        $this->assertEqualsCanonicalizing([$kadepA->id, $sekdepA->id], $recipients->pluck('id')->all());
        $this->assertNotContains($kadepB->id, $recipients->pluck('id')->all());
    }

    public function test_prodi_stage_without_scope_id_resolves_to_nobody(): void
    {
        $dept = $this->department('DEPT');
        $this->akademik('kaprodi', studyProgramId: $this->studyProgram('TRPL', $dept->id)->id);

        $recipients = $this->resolver->academicApprovers(['kaprodi', 'sekprodi'], studyProgramId: null, departmentId: null);

        $this->assertTrue($recipients->isEmpty());
    }

    public function test_super_admins_are_active_only(): void
    {
        $active = $this->superAdmin();
        $this->bookingUser(['role' => 'super_admin', 'role_level' => 'secondary', 'status' => UserStatus::Suspended]);

        $recipients = $this->resolver->superAdmins();

        $this->assertSame([$active->id], $recipients->pluck('id')->all());
    }

    private function akademik(string $subRole, ?int $studyProgramId = null, ?int $departmentId = null)
    {
        return $this->bookingUser([
            'role' => 'akademik',
            'sub_role' => $subRole,
            'study_program_id' => $studyProgramId,
            'department_id' => $departmentId,
            'status' => UserStatus::Active,
        ]);
    }

    private function department(string $code): Department
    {
        return Department::create(['code' => $code.'-'.uniqid(), 'name' => "Dept {$code}", 'faculty_id' => null]);
    }

    private function studyProgram(string $code, int $departmentId): StudyProgram
    {
        return StudyProgram::create(['code' => $code.'-'.uniqid(), 'name' => "Prodi {$code}", 'department_id' => $departmentId]);
    }
}
