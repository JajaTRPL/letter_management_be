<?php

namespace App\Http\Resources;

use App\Models\DelegatedActivityAcknowledgement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DelegatedActivityAcknowledgementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DelegatedActivityAcknowledgement $task */
        $task = $this->resource;
        $user = $request->user();
        $overdueLabel = $task->isOverdue() ? 'Melewati Batas Peninjauan' : null;

        return [
            'id' => (int) $task->id,
            'domain_type' => $task->domain_type,
            'subject_type' => $task->subject_type,
            'subject_id' => $task->subject_id,
            'delegated_actor' => $this->userSummary($task->delegatedActor),
            'accountable_user' => $this->userSummary($task->accountableUser),
            'accountable_role' => $task->accountable_role,
            'represented_scope_type' => $task->represented_scope_type,
            'represented_scope_id' => $task->represented_scope_id,
            'activity_type' => $task->activity_type,
            'activity_summary' => $task->activity_summary,
            'internal_note' => $this->when($this->canViewInternalNote($user, $task), $task->internal_note),
            'student_facing_note' => $task->student_facing_note,
            'before_state' => $this->when($this->canViewInternalNote($user, $task), $task->before_state),
            'after_state' => $this->when($this->canViewInternalNote($user, $task), $task->after_state),
            'status' => $task->status,
            'effective_status' => $task->effectiveStatus(),
            'urgency' => $task->urgency,
            'performed_at' => $task->performed_at?->toIso8601String(),
            'acknowledgement_due_at' => $task->acknowledgement_due_at?->toIso8601String(),
            'is_overdue' => $task->isOverdue(),
            'overdue_hours' => $task->overdueHours(),
            'overdue_days' => $task->overdueDays(),
            'acknowledged_at' => $task->acknowledged_at?->toIso8601String(),
            'acknowledged_by' => $this->userSummary($task->acknowledgedBy),
            'acknowledgement_note' => $task->acknowledgement_note,
            'escalated_at' => $task->escalated_at?->toIso8601String(),
            'escalation_seen_by_superadmin_at' => $task->escalation_seen_by_superadmin_at?->toIso8601String(),
            'status_label' => $this->statusLabel($task->status),
            'urgency_label' => $this->urgencyLabel($task->urgency),
            'overdue_label' => $overdueLabel,
            'labels' => [
                'status' => $this->statusLabel($task->status),
                'urgency' => $this->urgencyLabel($task->urgency),
                'overdue' => $overdueLabel,
            ],
            'permissions' => [
                'can_acknowledge' => $this->canAcknowledge($user, $task),
                'can_mark_escalation_seen' => $this->canMarkEscalationSeen($user, $task),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userSummary(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'tendik_role' => $user->tendik_role,
        ];
    }

    private function canViewInternalNote(?User $user, DelegatedActivityAcknowledgement $task): bool
    {
        return $user !== null
            && (
                $user->role === 'super_admin'
                || (int) $task->accountable_user_id === (int) $user->id
            );
    }

    private function canAcknowledge(?User $user, DelegatedActivityAcknowledgement $task): bool
    {
        return $user !== null
            && $user->isKalab()
            && $task->canBeAcknowledged()
            && (int) $task->accountable_user_id === (int) $user->id
            && (int) $task->delegated_actor_id !== (int) $user->id;
    }

    private function canMarkEscalationSeen(?User $user, DelegatedActivityAcknowledgement $task): bool
    {
        return $user !== null
            && app(\App\Services\DelegatedActivityAcknowledgementService::class)
                ->canMarkEscalationSeen($user, $task);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            DelegatedActivityAcknowledgement::STATUS_PENDING_REVIEW => 'Menunggu Peninjauan Kepala Lab',
            DelegatedActivityAcknowledgement::STATUS_ACKNOWLEDGED => 'Sudah Ditinjau',
            DelegatedActivityAcknowledgement::STATUS_ESCALATED => 'Perlu Atensi SuperAdmin',
            DelegatedActivityAcknowledgement::STATUS_VOIDED => 'Dibatalkan',
            default => $status,
        };
    }

    private function urgencyLabel(string $urgency): string
    {
        return match ($urgency) {
            DelegatedActivityAcknowledgement::URGENCY_URGENT => 'Mendesak',
            DelegatedActivityAcknowledgement::URGENCY_NORMAL => 'Normal',
            DelegatedActivityAcknowledgement::URGENCY_LOW_RISK => 'Risiko Rendah',
            default => $urgency,
        };
    }
}
