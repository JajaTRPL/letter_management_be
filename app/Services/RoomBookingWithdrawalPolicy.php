<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Enums\UserStatus;
use App\Models\RoomBookingCancellationRequest;
use App\Models\RoomBookingRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Pure lifecycle policy for requester withdrawal and reviewed cancellation.
 * Callers must still re-evaluate it after acquiring the booking lock.
 */
class RoomBookingWithdrawalPolicy
{
    /** @return array{allowed: bool, block_reason: ?string} */
    public function directWithdrawalDecision(?User $actor, RoomBookingRequest $booking): array
    {
        if (! $this->isActiveOwner($actor, $booking)) {
            return $this->blocked(RoomBookingDomainException::UNAUTHORIZED_ACTION);
        }

        if ($booking->hasPendingCancellationRequest()) {
            return $this->blocked(RoomBookingDomainException::PENDING_CANCELLATION_REQUEST);
        }

        if ($booking->status !== RoomBookingStatus::Submitted) {
            return $this->blocked(match ($booking->status) {
                RoomBookingStatus::RevisionRequested => RoomBookingDomainException::REVISION_ALREADY_REQUESTED,
                RoomBookingStatus::Approved => RoomBookingDomainException::REQUIRES_CANCELLATION_REVIEW,
                RoomBookingStatus::Rejected,
                RoomBookingStatus::Cancelled => RoomBookingDomainException::FINAL_BOOKING_STATE,
            });
        }

        if ($booking->isExpired() || $booking->start_at === null) {
            return $this->blocked(RoomBookingDomainException::BOOKING_EXPIRED);
        }

        if ($booking->review_started_at !== null) {
            return $this->blocked(RoomBookingDomainException::REVIEW_ALREADY_STARTED);
        }

        if ($booking->hasRevisionBeenRequested()) {
            return $this->blocked(RoomBookingDomainException::REVISION_ALREADY_REQUESTED);
        }

        $cutoffAt = $this->now()->addHours($this->cutoffHours());
        if ($booking->start_at->lessThan($cutoffAt)) {
            return $this->blocked(RoomBookingDomainException::WITHDRAWAL_CUTOFF_PASSED);
        }

        return ['allowed' => true, 'block_reason' => null];
    }

    public function canWithdraw(?User $actor, RoomBookingRequest $booking): bool
    {
        return $this->directWithdrawalDecision($actor, $booking)['allowed'];
    }

    public function canRequestCancellation(?User $actor, RoomBookingRequest $booking): bool
    {
        if (! $this->isActiveOwner($actor, $booking)) {
            return false;
        }

        if ($booking->hasPendingCancellationRequest()) {
            return false;
        }

        if (! in_array($booking->status, [
            RoomBookingStatus::Submitted,
            RoomBookingStatus::RevisionRequested,
            RoomBookingStatus::Approved,
        ], true)) {
            return false;
        }

        if (
            $booking->isCompleted()
            || $booking->start_at === null
            || ! $booking->start_at->greaterThan($this->now())
        ) {
            return false;
        }

        return ! $this->canWithdraw($actor, $booking);
    }

    public function canWithdrawCancellationRequest(
        ?User $actor,
        RoomBookingRequest $booking,
        ?RoomBookingCancellationRequest $cancellationRequest = null,
    ): bool {
        $cancellationRequest ??= $booking->activeCancellationRequest;

        return $this->isActiveOwner($actor, $booking)
            && $booking->status !== RoomBookingStatus::Cancelled
            && $booking->start_at !== null
            && $booking->start_at->greaterThan($this->now())
            && $cancellationRequest?->isPending() === true
            && (int) $cancellationRequest->requested_by === (int) $actor?->id;
    }

    public function nextAction(?User $actor, RoomBookingRequest $booking): ?string
    {
        if ($this->canWithdrawCancellationRequest($actor, $booking)) {
            return 'withdraw_cancellation_request';
        }

        if ($this->canWithdraw($actor, $booking)) {
            return 'withdraw';
        }

        if ($this->canRequestCancellation($actor, $booking)) {
            return 'request_cancellation';
        }

        if (
            $this->isActiveOwner($actor, $booking)
            && $booking->status === RoomBookingStatus::RevisionRequested
        ) {
            return $booking->isExpired() ? 'edit_schedule' : 'resubmit';
        }

        return null;
    }

    private function isActiveOwner(?User $actor, RoomBookingRequest $booking): bool
    {
        return $actor !== null
            && $actor->role === 'mahasiswa'
            && $actor->status === UserStatus::Active
            && (int) $booking->requester_id === (int) $actor->id;
    }

    /** @return array{allowed: false, block_reason: string} */
    private function blocked(string $reason): array
    {
        return ['allowed' => false, 'block_reason' => $reason];
    }

    private function cutoffHours(): int
    {
        return max(0, (int) config('room_booking.self_withdrawal_cutoff_hours', 24));
    }

    private function now(): Carbon
    {
        return Carbon::now(config('app.timezone'));
    }
}
