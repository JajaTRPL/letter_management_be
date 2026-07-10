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
     */
    public function recordLaboranFacilitySyncIfNeeded(
        Room $room,
        User $actor,
        array $beforeState,
        array $afterState,
    ): ?DelegatedActivityAcknowledgement {
        if (! $actor->isLaboran()) {
            return null;
        }

        if ($room->type !== RoomType::Laboratory || ! $room->owning_laboratory_id) {
            return null;
        }

        if ($beforeState === $afterState) {
            return null;
        }

        $kepalaLab = $this->reviewerResolver->findActiveKepalaLab($room);
        if (! $kepalaLab) {
            $this->logSkippedNoActiveKepalaLab($room, $actor);

            return null;
        }

        return $this->acknowledgements->createTask([
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
