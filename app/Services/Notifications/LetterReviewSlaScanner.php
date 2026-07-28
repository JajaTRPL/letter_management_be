<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Models\User;
use App\Services\AcademicRoutingService;
use App\Support\LetterTypeRegistry;
use App\Support\LetterWorkflowStatus as LS;
use App\Support\Workflow\LetterReviewStageClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Review-SLA scanner for administrative letters (C11) — the letter twin of
 * RoomBookingReviewSlaScanner, on the SAME governance policy (scope=letter) and
 * the SAME notification backbone.
 *
 * Letters have THREE sequential review stages, each with a different responsible
 * owner and its own clock:
 *   Submitted        → Tendik Persuratan      (waiting since submitted_at)
 *   Approved_Tendik  → Kaprodi/Sekprodi        (waiting since tendik_approved_at)
 *   Approved_Kaprodi → Kadep/Sekdep            (waiting since kaprodi_approved_at)
 * Reviewer resolution reuses NotificationRecipientResolver (the exact resolution
 * the projector uses on live transitions), so the SLA never invents recipients.
 *
 * Ships inert (disabled policy → no-op). Elapsed-range phases are catch-up safe;
 * per-letter, per-stage, per-cycle dedup keys are idempotent. When a letter
 * advances / is revised / rejected / completed the projector resolves its open
 * review-SLA notifications, so the obligation ends the moment the stage does.
 */
class LetterReviewSlaScanner
{
    /**
     * The five administrative letter models that share the review vocabulary.
     * Public so review analytics samples exactly the same set — a letter type
     * present in one and missing from the other would produce a dashboard that
     * silently under-reports the workload it is judging.
     */
    public const LETTER_MODELS = [
        SuratKeteranganAktifApplication::class,
        SuratPengantarMagangApplication::class,
        ProsesLuarNegeriApplication::class,
        SuratTugasApplication::class,
        ScholarshipApplication::class,
    ];

    private const REVIEW_STATUSES = [LS::SUBMITTED, LS::APPROVED_TENDIK, LS::APPROVED_KAPRODI];

    public function __construct(
        private NotificationWriter $writer,
        private NotificationRecipientResolver $recipients,
        private WorkflowReviewSlaPolicyService $policies,
        private AcademicRoutingService $routing,
    ) {}

    /** @return array{emitted:int,scanned:int,enabled:bool} */
    public function scan(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now(config('app.timezone')))->copy();
        $policy = $this->policies->current(WorkflowReviewSlaPolicyService::SCOPE_LETTER);

        if (! $policy['enabled']) {
            return ['emitted' => 0, 'scanned' => 0, 'enabled' => false];
        }

        $emitted = 0;
        $scanned = 0;

        foreach (self::LETTER_MODELS as $modelClass) {
            /** @var class-string<Model> $modelClass */
            $rows = $modelClass::query()
                ->with('user')
                ->whereIn('status', self::REVIEW_STATUSES)
                ->get();
            $scanned += $rows->count();
            foreach ($rows as $application) {
                $emitted += $this->project($application, $modelClass::LETTER_TYPE, $now, $policy);
            }
        }

        return ['emitted' => $emitted, 'scanned' => $scanned, 'enabled' => true];
    }

    /**
     * @param  array{enabled:bool,warning_minutes:int,overdue_minutes:int,escalation_minutes:int}  $policy
     */
    private function project(Model $application, string $letterType, Carbon $now, array $policy): int
    {
        $status = (string) $application->getAttribute('status');
        $since = $this->waitingSince($application, $status);
        if (! $since) {
            return 0;
        }

        $elapsed = intdiv($now->getTimestamp() - $since->copy()->getTimestamp(), 60);
        if ($elapsed < $policy['warning_minutes']) {
            return 0;
        }

        $reviewers = $this->stageReviewers($application, $status);
        $id = (string) $application->getKey();
        $stage = $this->stageKey($status);
        $epoch = (string) ($application->getAttribute('submitted_at')?->timestamp ?? 0);
        $route = $status === LS::SUBMITTED
            ? NotificationActionRoute::PERSURATAN_LETTER_QUEUE
            : NotificationActionRoute::AKADEMIK_LETTER_QUEUE;
        $line = LetterTypeRegistry::labelFor($letterType);
        $emitted = 0;

        if ($elapsed >= $policy['escalation_minutes']) {
            $emitted += $this->emitToReviewers($reviewers, $letterType, $id, $stage, $epoch, $route, $since, 'overdue', $line);
            $emitted += $this->emitEscalation($letterType, $id, $stage, $epoch, $now, $line);
        } elseif ($elapsed >= $policy['overdue_minutes']) {
            $emitted += $this->emitToReviewers($reviewers, $letterType, $id, $stage, $epoch, $route, $since, 'overdue', $line);
        } else {
            $emitted += $this->emitToReviewers($reviewers, $letterType, $id, $stage, $epoch, $route, $since, 'warning', $line);
        }

        return $emitted;
    }

    /**
     * @param  Collection<int, User>  $reviewers
     */
    private function emitToReviewers(
        Collection $reviewers,
        string $letterType,
        string $id,
        string $stage,
        string $epoch,
        string $route,
        Carbon $since,
        string $phase,
        string $line,
    ): int {
        $overdue = $phase === 'overdue';
        $emitted = 0;
        foreach ($reviewers as $reviewer) {
            $emitted += $this->write(
                $reviewer,
                $overdue ? WorkflowReviewSlaPolicyService::EVENT_OVERDUE : WorkflowReviewSlaPolicyService::EVENT_WARNING,
                $overdue ? NotificationCategory::ActionRequired : NotificationCategory::Reminder,
                $overdue ? NotificationPriority::High : NotificationPriority::Normal,
                $overdue ? 'Pemeriksaan surat terlambat' : 'Surat menunggu pemeriksaan',
                $overdue
                    ? $line.' telah melewati batas waktu pemeriksaan dan menunggu keputusan Anda.'
                    : $line.' sudah menunggu pemeriksaan dan mendekati batas waktu.',
                "letter-review-sla-{$phase}:{$letterType}:{$id}:{$stage}:{$epoch}:u:{$reviewer->id}",
                $letterType,
                $id,
                $route,
                $overdue ? 'Tinjau Sekarang' : 'Tinjau Surat',
                $since,
                // Overdue retires the soft warning for the same reviewer + stage.
                $overdue ? ["letter-review-sla-warning:{$letterType}:{$id}:{$stage}:{$epoch}:u:{$reviewer->id}"] : [],
            );
        }

        return $emitted;
    }

    private function emitEscalation(string $letterType, string $id, string $stage, string $epoch, Carbon $now, string $line): int
    {
        $emitted = 0;
        foreach ($this->recipients->superAdmins() as $admin) {
            $emitted += $this->write(
                $admin,
                WorkflowReviewSlaPolicyService::EVENT_ESCALATION,
                NotificationCategory::System,
                NotificationPriority::High,
                'Perlu perhatian: surat belum diperiksa',
                $line.' sudah lama menunggu dan belum diperiksa oleh pemeriksa.',
                "letter-review-sla-escalation:{$letterType}:{$id}:{$stage}:{$epoch}:admin:{$admin->id}",
                $letterType,
                $id,
                NotificationActionRoute::SUPERADMIN_HEALTH,
                'Tinjau Kesehatan Sistem',
                $now,
            );
        }

        return $emitted;
    }

    /** @param list<string> $supersedes */
    private function write(
        User $recipient,
        string $eventType,
        NotificationCategory $category,
        NotificationPriority $priority,
        string $title,
        string $body,
        string $dedupKey,
        string $letterType,
        string $id,
        string $route,
        string $label,
        Carbon $occurredAt,
        array $supersedes = [],
    ): int {
        $notification = $this->writer->write(new NotificationIntent(
            recipient: $recipient,
            eventType: $eventType,
            category: $category,
            priority: $priority,
            title: $title,
            body: $body,
            dedupKey: $dedupKey,
            subjectType: $letterType,
            subjectPublicId: $id,
            actionRouteKey: $route,
            actionLabel: $label,
            occurredAt: $occurredAt,
            supersedesDedupPrefixes: $supersedes,
        ));

        return $notification->wasRecentlyCreated ? 1 : 0;
    }

    private function waitingSince(Model $application, string $status): ?Carbon
    {
        return LetterReviewStageClock::waitingSince($application, $status);
    }

    /** @return Collection<int, User> */
    private function stageReviewers(Model $application, string $status): Collection
    {
        return match ($status) {
            LS::SUBMITTED => $this->recipients->letterPersuratan(
                $application->getAttribute('assigned_to') !== null
                    ? (int) $application->getAttribute('assigned_to')
                    : null,
            ),
            LS::APPROVED_TENDIK => $this->recipients->academicApprovers(
                ['kaprodi', 'sekprodi'],
                $this->routing->studentStudyProgramId($application),
                null,
            ),
            LS::APPROVED_KAPRODI => $this->recipients->academicApprovers(
                ['kadep', 'sekdep'],
                null,
                $this->routing->studentDepartmentId($application),
            ),
            default => collect(),
        };
    }

    /**
     * `unknown` is preserved for non-review statuses because this value is part
     * of a persisted dedup key — the clock returns null there, and changing the
     * key shape would orphan every open notification.
     */
    private function stageKey(string $status): string
    {
        return LetterReviewStageClock::stageKeyFor($status) ?? 'unknown';
    }
}
