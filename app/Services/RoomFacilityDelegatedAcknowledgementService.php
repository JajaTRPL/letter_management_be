<?php

namespace App\Services;

use App\Enums\RoomType;
use App\Models\ActivityLog;
use App\Models\DelegatedActivityAcknowledgement;
use App\Models\Room;
use App\Models\RoomFacility;
use App\Models\User;

class RoomFacilityDelegatedAcknowledgementService
{
    private const MESSAGE_CREATED = 'Perubahan fasilitas tersimpan dan menunggu peninjauan Kepala Lab.';
    private const MESSAGE_EXISTING = 'Perubahan fasilitas tersimpan. Aktivitas peninjauan sudah tercatat sebelumnya.';
    private const MESSAGE_NO_ACTIVE_KEPALA_LAB = 'Perubahan fasilitas tersimpan. Belum ada Kepala Lab aktif untuk peninjauan otomatis.';
    private const MESSAGE_NO_EFFECTIVE_CHANGE = 'Tidak ada perubahan fasilitas yang perlu ditinjau.';
    private const MESSAGE_NOT_APPLICABLE = 'Perubahan fasilitas tersimpan.';

    public function __construct(
        private RoomBookingReviewerResolver $reviewerResolver,
        private DelegatedActivityAcknowledgementService $acknowledgements,
    ) {
    }

    /**
     * @return array{room: array{id: int, code: ?string, name: ?string}, facilities: array<int, array{facility_type_id: ?int, name: ?string, quantity: ?int, condition: ?string, notes: ?string}>}
     */
    public function facilityState(Room $room): array
    {
        $facilities = $room->facilityItems()
            ->with('facilityType:id,name')
            ->get()
            ->map(fn (RoomFacility $facility): array => [
                'facility_type_id' => $facility->facility_type_id !== null ? (int) $facility->facility_type_id : null,
                'name' => $this->blankToNull($facility->facilityType?->name),
                'quantity' => $facility->quantity !== null ? (int) $facility->quantity : null,
                'condition' => $this->blankToNull($facility->condition),
                'notes' => $this->blankToNull($facility->notes),
            ])
            ->sortBy(fn (array $facility): string => sprintf(
                '%010d|%s',
                $facility['facility_type_id'] ?? 0,
                $facility['name'] ?? '',
            ))
            ->values()
            ->all();

        return [
            'room' => [
                'id' => (int) $room->id,
                'code' => $this->blankToNull($room->code),
                'name' => $this->blankToNull($room->name),
            ],
            'facilities' => $facilities,
        ];
    }

    /**
     * @param  array<string, mixed>  $beforeState
     * @param  array<string, mixed>  $afterState
     * @return array{outcome: 'created'|'existing'|'skipped'|'not_applicable', acknowledgement: ?DelegatedActivityAcknowledgement, reason: ?string, message: string}
     */
    public function recordLaboranFacilitySyncIfNeeded(
        Room $room,
        User $actor,
        array $beforeState,
        array $afterState,
    ): array {
        if (! $actor->isLaboran()) {
            return $this->result('not_applicable', null, 'actor_not_laboran', self::MESSAGE_NOT_APPLICABLE);
        }

        if ($room->type !== RoomType::Laboratory || ! $room->owning_laboratory_id) {
            return $this->result('not_applicable', null, 'room_not_laboratory', self::MESSAGE_NOT_APPLICABLE);
        }

        if ($beforeState === $afterState) {
            return $this->result('skipped', null, 'no_effective_change', self::MESSAGE_NO_EFFECTIVE_CHANGE);
        }

        $kepalaLab = $this->reviewerResolver->findActiveKepalaLab($room);
        if (! $kepalaLab) {
            $this->logSkippedNoActiveKepalaLab($room, $actor);

            return $this->result('skipped', null, 'no_active_kepala_lab', self::MESSAGE_NO_ACTIVE_KEPALA_LAB);
        }

        $taskResult = $this->acknowledgements->createTaskWithOutcome([
            'domain_type' => 'room_management',
            'subject_type' => 'room',
            'subject_id' => (int) $room->id,
            'idempotency_key' => $this->idempotencyKey($room, $actor, $beforeState, $afterState),
            'delegated_actor_id' => (int) $actor->id,
            'accountable_user_id' => (int) $kepalaLab->id,
            'accountable_role' => 'kepala_lab',
            'represented_scope_type' => 'laboratory',
            'represented_scope_id' => (int) $room->owning_laboratory_id,
            'activity_type' => 'lab_facility_condition_synced',
            'activity_summary' => sprintf(
                'Laboran memperbarui kondisi fasilitas %s - %s.',
                $room->code ?? '-',
                $room->name ?? '-',
            ),
            'internal_note' => 'Perubahan fasilitas laboratorium dicatat untuk peninjauan Kepala Lab.',
            'student_facing_note' => null,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'urgency' => DelegatedActivityAcknowledgement::URGENCY_NORMAL,
            'performed_at' => now(config('app.timezone')),
        ]);

        return $this->result(
            $taskResult['outcome'],
            $taskResult['acknowledgement'],
            null,
            $taskResult['outcome'] === 'existing' ? self::MESSAGE_EXISTING : self::MESSAGE_CREATED,
        );
    }

    /**
     * @param  array{outcome: 'created'|'existing'|'skipped'|'not_applicable', acknowledgement: ?DelegatedActivityAcknowledgement, reason: ?string, message: string}  $result
     * @return array{outcome: string, id: ?int, status: ?string, effective_status: ?string, acknowledgement_due_at: ?string, accountable_role: ?string, accountable_user: ?array{id: int, name: ?string}, message: string, reason: ?string}
     */
    public function responseMetadata(array $result): array
    {
        $task = $result['acknowledgement'];
        if ($task) {
            $task->loadMissing('accountableUser');
        }

        return [
            'outcome' => $result['outcome'],
            'id' => $task ? (int) $task->id : null,
            'status' => $task?->status,
            'effective_status' => $task?->effectiveStatus(),
            'acknowledgement_due_at' => $task?->acknowledgement_due_at?->toIso8601String(),
            'accountable_role' => $task?->accountable_role,
            'accountable_user' => $task?->accountableUser ? [
                'id' => (int) $task->accountableUser->id,
                'name' => $this->blankToNull($task->accountableUser->name),
            ] : null,
            'message' => $result['message'],
            'reason' => $result['reason'],
        ];
    }

    /**
     * @return array{outcome: 'created'|'existing'|'skipped'|'not_applicable', acknowledgement: ?DelegatedActivityAcknowledgement, reason: ?string, message: string}
     */
    private function result(
        string $outcome,
        ?DelegatedActivityAcknowledgement $acknowledgement,
        ?string $reason,
        string $message,
    ): array {
        return [
            'outcome' => $outcome,
            'acknowledgement' => $acknowledgement,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $beforeState
     * @param  array<string, mixed>  $afterState
     */
    private function idempotencyKey(Room $room, User $actor, array $beforeState, array $afterState): string
    {
        $beforeHash = hash('sha256', $this->canonicalJson($beforeState));
        $afterHash = hash('sha256', $this->canonicalJson($afterState));

        $key = sprintf('room_facility_sync:%d:%d:%s:%s', $room->id, $actor->id, $beforeHash, $afterHash);
        if (strlen($key) <= 160) {
            return $key;
        }

        return sprintf(
            'room_facility_sync:%d:%d:%s:%s',
            $room->id,
            $actor->id,
            substr($beforeHash, 0, 48),
            substr($afterHash, 0, 48),
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function canonicalJson(array $state): string
    {
        return json_encode(
            $state,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function logSkippedNoActiveKepalaLab(Room $room, User $actor): void
    {
        ActivityLog::create([
            'user_id' => $actor->id,
            'type' => 'delegated_activity',
            'action' => 'Delegated activity skipped',
            'target_user' => 'room:'.$room->id,
            'details' => sprintf(
                'Aktivitas delegasi fasilitas lab tidak dibuat karena tidak ada Kepala Lab aktif untuk %s - %s.',
                $room->code ?? '-',
                $room->name ?? '-',
            ),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
