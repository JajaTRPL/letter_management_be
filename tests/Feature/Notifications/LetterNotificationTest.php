<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Models\AppNotification;
use App\Models\SuratKeteranganAktifApplication as Ska;
use App\Models\User;
use App\Services\LetterAssignmentService;
use App\Services\Notifications\NotificationProjector as P;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Notifications\NotificationWriter;
use App\Support\LetterWorkflowStatus as LS;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * Administrative-letter notification matrix, driven through the REAL seams: the
 * shared status-transition observer on the letter models and the assignment
 * service. SKA is the representative type; all five share the observer and the
 * assignment seam, so the wiring is identical.
 */
class LetterNotificationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const TYPE = 'surat-keterangan-aktif';

    // ── submission → Persuratan (concrete assignee) ───────────────────────

    public function test_submission_notifies_only_the_assigned_persuratan_officer(): void
    {
        $student = $this->mahasiswa();
        $assignee = $this->tendikPersuratan([self::TYPE]);
        $otherAssignee = $this->tendikPersuratan(['surat-tugas']); // different assignment scope
        $app = $this->draft($student);

        $this->submit($app);

        // Concrete assignee gets an action-required review item; the officer
        // assigned to other letter types does not.
        $this->assertRecipients(self::TYPE, P::LETTER_PERSURATAN_REVIEW, [$assignee->id]);
        $this->assertNoneFor($otherAssignee->id);
        // The applicant is never notified about their own successful submission.
        $this->assertNoneFor($student->id);

        $note = AppNotification::where('recipient_user_id', $assignee->id)->firstOrFail();
        $this->assertSame(NotificationCategory::ActionRequired->value, $note->category->value);
        $this->assertSame('persuratan.letter.queue', $note->action_route_key);
        $this->assertSame(self::TYPE, $note->subject_type);
        $this->assertSame((string) $app->id, $note->subject_public_id);
    }

    public function test_missing_persuratan_raises_a_superadmin_health_alert(): void
    {
        $admin = $this->primarySuperAdmin();
        $student = $this->mahasiswa();
        $app = $this->draft($student); // no eligible Persuratan officer exists

        $this->submit($app);

        $health = AppNotification::where('recipient_user_id', $admin->id)->firstOrFail();
        $this->assertSame(NotificationCategory::System->value, $health->category->value);
        $this->assertSame('superadmin.health', $health->action_route_key);
    }

    // ── Tendik approve → exact prodi academic scope ───────────────────────

    public function test_tendik_approval_targets_only_the_matching_prodi_approvers(): void
    {
        $student = $this->mahasiswa();
        $prodiId = (int) $student->study_program_id;
        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $prodiId]);
        $sekprodi = $this->akademik('sekprodi', ['study_program_id' => $prodiId]);
        $otherProdi = $this->studyProgram();
        $foreignKaprodi = $this->akademik('kaprodi', ['study_program_id' => $otherProdi->id]);
        $persuratan = $this->tendikPersuratan([self::TYPE]);

        $app = $this->draft($student);
        $this->submit($app);

        $this->transition($app, LS::APPROVED_TENDIK);

        // Exact prodi approvers only; a different prodi's Kaprodi never sees it.
        $this->assertRecipients(self::TYPE, P::LETTER_PRODI_REVIEW, [$kaprodi->id, $sekprodi->id]);
        $this->assertNoneWithEvent($foreignKaprodi->id, P::LETTER_PRODI_REVIEW);
        // The Persuratan review action is resolved by the advance.
        $this->assertResolved($persuratan->id, P::LETTER_PERSURATAN_REVIEW);
        // The applicant is NOT notified of this internal approval hop.
        $this->assertNoneFor($student->id);
    }

    public function test_kaprodi_approval_targets_only_the_matching_department_approvers(): void
    {
        $student = $this->mahasiswa();
        $deptId = (int) $student->studyProgram->department_id;
        $kadep = $this->akademik('kadep', ['department_id' => $deptId]);
        $sekdep = $this->akademik('sekdep', ['department_id' => $deptId]);
        $foreignKadep = $this->akademik('kadep', ['department_id' => $this->department()->id]);
        $this->tendikPersuratan([self::TYPE]);
        $prodiApprover = $this->akademik('kaprodi', ['study_program_id' => (int) $student->study_program_id]);

        $app = $this->draft($student);
        $this->submit($app);
        $this->transition($app, LS::APPROVED_TENDIK);
        $this->transition($app, LS::APPROVED_KAPRODI);

        $this->assertRecipients(self::TYPE, P::LETTER_DEPARTMENT_REVIEW, [$kadep->id, $sekdep->id]);
        $this->assertNoneWithEvent($foreignKadep->id, P::LETTER_DEPARTMENT_REVIEW);
        // The prodi review action is resolved by the advance.
        $this->assertResolved($prodiApprover->id, P::LETTER_PRODI_REVIEW);
        $this->assertNoneFor($student->id);
    }

    public function test_department_approval_notifies_the_applicant_and_resolves_review(): void
    {
        $student = $this->mahasiswa();
        $deptId = (int) $student->studyProgram->department_id;
        $kadep = $this->akademik('kadep', ['department_id' => $deptId]);
        $this->tendikPersuratan([self::TYPE]);

        $app = $this->draft($student);
        $this->submit($app);
        $this->transition($app, LS::APPROVED_TENDIK);
        $this->transition($app, LS::APPROVED_KAPRODI);
        $this->transition($app, LS::READY_FOR_STUDENT_REVIEW);

        $ready = AppNotification::where('recipient_user_id', $student->id)
            ->where('event_type', P::LETTER_READY_FOR_STUDENT)->firstOrFail();
        $this->assertSame(NotificationCategory::Update->value, $ready->category->value);
        $this->assertSame('mahasiswa.letter.detail', $ready->action_route_key);
        $this->assertResolved($kadep->id, P::LETTER_DEPARTMENT_REVIEW);
    }

    // ── revision / rejection ──────────────────────────────────────────────

    public function test_revision_notifies_applicant_and_resolves_the_stage_reviewer(): void
    {
        $student = $this->mahasiswa();
        $persuratan = $this->tendikPersuratan([self::TYPE]);
        $app = $this->draft($student);
        $this->submit($app);

        $this->transition($app, LS::REVISION);

        $revision = AppNotification::where('recipient_user_id', $student->id)
            ->where('event_type', P::LETTER_REVISION_REQUESTED)->firstOrFail();
        $this->assertSame(NotificationCategory::ActionRequired->value, $revision->category->value);
        $this->assertSame('mahasiswa.letter.detail', $revision->action_route_key);
        // The Persuratan review action is resolved (they made the decision).
        $this->assertResolved($persuratan->id, P::LETTER_PERSURATAN_REVIEW);
    }

    public function test_resubmission_resolves_the_revision_and_opens_a_fresh_persuratan_item(): void
    {
        $student = $this->mahasiswa();
        $persuratan = $this->tendikPersuratan([self::TYPE]);
        $app = $this->draft($student);
        $this->submit($app);
        $this->transition($app, LS::REVISION);
        $this->assertNotNull(AppNotification::where('recipient_user_id', $student->id)
            ->where('event_type', P::LETTER_REVISION_REQUESTED)->value('id'));

        // Resubmit: new submission cycle (new submitted_at epoch).
        $app->forceFill(['submitted_at' => now()->addMinutes(5)])->save();
        $this->submit($app, LS::REVISION);

        // Applicant's revision action is resolved by the valid resubmission…
        $this->assertResolved($student->id, P::LETTER_REVISION_REQUESTED);
        // …and a fresh, distinct Persuratan review item exists (two cycles).
        $this->assertSame(
            2,
            AppNotification::where('recipient_user_id', $persuratan->id)
                ->where('event_type', P::LETTER_PERSURATAN_REVIEW)->count(),
        );
        $this->assertSame(
            1,
            AppNotification::where('recipient_user_id', $persuratan->id)
                ->where('event_type', P::LETTER_PERSURATAN_REVIEW)
                ->whereNull('resolved_at')->count(),
        );
    }

    public function test_rejection_from_prodi_stage_resolves_prodi_review_and_notifies_applicant(): void
    {
        $student = $this->mahasiswa();
        $prodiId = (int) $student->study_program_id;
        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $prodiId]);
        $this->tendikPersuratan([self::TYPE]);
        $app = $this->draft($student);
        $this->submit($app);
        $this->transition($app, LS::APPROVED_TENDIK);

        $this->transition($app, LS::REJECTED);

        $this->assertResolved($kaprodi->id, P::LETTER_PRODI_REVIEW);
        $this->assertDatabaseHas('app_notifications', [
            'recipient_user_id' => $student->id,
            'event_type' => P::LETTER_REJECTED,
            'subject_public_id' => (string) $app->id,
        ]);
    }

    // ── dedup / privacy / rollback ────────────────────────────────────────

    public function test_repeated_transition_processing_is_idempotent(): void
    {
        $student = $this->mahasiswa();
        $prodiId = (int) $student->study_program_id;
        $kaprodi = $this->akademik('kaprodi', ['study_program_id' => $prodiId]);
        $this->tendikPersuratan([self::TYPE]);
        $app = $this->draft($student);
        $this->submit($app);

        // Re-project the same transition three times (retries / at-least-once).
        for ($i = 0; $i < 3; $i++) {
            app(P::class)->projectLetterTransition($app->fresh(), self::TYPE, LS::SUBMITTED, LS::APPROVED_TENDIK);
        }

        $this->assertSame(
            1,
            AppNotification::where('recipient_user_id', $kaprodi->id)
                ->where('event_type', P::LETTER_PRODI_REVIEW)->count(),
        );
    }

    public function test_notification_body_carries_no_private_metadata(): void
    {
        $student = $this->mahasiswa();
        $assignee = $this->tendikPersuratan([self::TYPE]);
        $app = $this->draft($student, ['keperluan' => 'RAHASIA-JANGAN-BOCOR']);
        $this->submit($app);

        $note = AppNotification::where('recipient_user_id', $assignee->id)->firstOrFail();
        $blob = strtolower($note->title.' '.$note->body);
        $this->assertStringNotContainsString('rahasia', $blob);
        $this->assertStringNotContainsString('http', (string) $note->action_route_key);
        $this->assertStringNotContainsString('storage', $blob);
    }

    public function test_no_letter_notification_survives_a_rolled_back_transition(): void
    {
        $student = $this->mahasiswa();
        $this->akademik('kaprodi', ['study_program_id' => (int) $student->study_program_id]);
        $this->tendikPersuratan([self::TYPE]);
        $app = $this->draft($student);
        $this->submit($app);
        $before = AppNotification::count();

        try {
            DB::transaction(function () use ($app): void {
                $this->transition($app, LS::APPROVED_TENDIK);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // The transition and its notification were discarded together.
        $this->assertSame($before, AppNotification::count());
        $this->assertSame(LS::SUBMITTED, $app->fresh()->status);
    }

    public function test_notification_failure_does_not_break_the_letter_workflow(): void
    {
        $this->app->bind(P::class, function ($app) {
            return new class($app->make(NotificationWriter::class), $app->make(NotificationRecipientResolver::class)) extends P
            {
                public function projectLetterTransition($application, string $letterType, ?string $from, string $to): void
                {
                    $this->safely(function (): void {
                        throw new \RuntimeException('boom');
                    });
                }
            };
        });

        $student = $this->mahasiswa();
        $this->tendikPersuratan([self::TYPE]);
        $app = $this->draft($student);

        // The transition still succeeds despite the broken projector.
        $app->update(['status' => LS::SUBMITTED, 'submitted_at' => now()]);
        $this->assertSame(LS::SUBMITTED, $app->fresh()->status);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function mahasiswa(): User
    {
        [$student] = $this->completeMahasiswa();

        return $student->fresh(['studyProgram']);
    }

    private function draft(User $student, array $attributes = []): Ska
    {
        return $this->aktifApplication($student, array_merge(['status' => LS::DRAFT], $attributes));
    }

    /** Real submission: set Submitted, then run the shared assignment seam. */
    private function submit(Ska $app, string $fromStatus = LS::DRAFT): void
    {
        $app->update(['status' => LS::SUBMITTED, 'submitted_at' => $app->submitted_at ?? now()]);
        app(LetterAssignmentService::class)->assignToEligibleTendik($app->fresh(), self::TYPE);
    }

    private function transition(Ska $app, string $to): void
    {
        $app->update(['status' => $to]);
    }

    /** @param list<int> $expectedIds */
    private function assertRecipients(string $type, string $eventType, array $expectedIds): void
    {
        $actual = AppNotification::where('subject_type', $type)
            ->where('event_type', $eventType)
            ->pluck('recipient_user_id')->sort()->values()->all();
        sort($expectedIds);
        $this->assertSame($expectedIds, $actual);
    }

    private function assertNoneFor(int $userId): void
    {
        $this->assertSame(0, AppNotification::where('recipient_user_id', $userId)->count());
    }

    private function assertNoneWithEvent(int $userId, string $eventType): void
    {
        $this->assertSame(0, AppNotification::where('recipient_user_id', $userId)
            ->where('event_type', $eventType)->count());
    }

    private function assertResolved(int $userId, string $eventType): void
    {
        $note = AppNotification::where('recipient_user_id', $userId)
            ->where('event_type', $eventType)->first();
        $this->assertNotNull($note, "expected a {$eventType} notification for user {$userId}");
        $this->assertNotNull($note->resolved_at, "expected {$eventType} for user {$userId} to be resolved");
    }
}
