<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingRequest;
use App\Models\User;

/**
 * Server-authoritative capability projection for room bookings. Uses the
 * same authorization sources as the mutation services (ownership for
 * applicant actions, RoomBookingReviewerResolver for reviewer actions), so
 * the flags cannot drift from what the endpoints actually allow.
 *
 * C7B1 scope: only currently implemented actions. Withdrawal/cancellation-
 * request capabilities arrive in C7B2. `can_cancel` mirrors the LEGACY
 * immediate-cancel endpoint behavior for compatibility.
 */
class RoomBookingLifecycleCapabilityResolver
{
    public function __construct(
        private RoomBookingReviewerResolver $reviewerResolver,
        private RoomBookingWithdrawalPolicy $withdrawalPolicy,
    ) {}

    /**
     * @return array<string, bool|string|null>
     */
    public function capabilitiesFor(?User $actor, RoomBookingRequest $booking): array
    {
        $capabilities = [
            'can_edit' => false,
            'can_resubmit' => false,
            'can_cancel' => false,
            'can_review' => false,
            'can_start_review' => false,
            'can_approve' => false,
            'can_request_revision' => false,
            'can_reject' => false,
            'can_view_attachment' => false,
            'can_withdraw' => false,
            'can_request_cancellation' => false,
            'can_withdraw_cancellation_request' => false,
            'can_decide_cancellation' => false,
            'withdrawal_block_reason' => null,
            'next_action' => null,
        ];

        if (! $actor) {
            return $capabilities;
        }

        $capabilities['can_view_attachment'] = $this->canViewAttachment($actor, $booking);
        $booking->loadMissing([
            'activeCancellationRequest',
            'revisionRequestHistory',
            'room',
        ]);

        if (
            $booking->isCompleted()
            || in_array($booking->status, [
                RoomBookingStatus::Rejected,
                RoomBookingStatus::Cancelled,
            ], true)
        ) {
            return $capabilities;
        }

        $isOwner = $actor->role === 'mahasiswa'
            && (int) $booking->requester_id === (int) $actor->id;

        if ($isOwner) {
            $inRevision = $booking->status === RoomBookingStatus::RevisionRequested;
            $pendingCancellation = $booking->hasPendingCancellationRequest();
            $withdrawalDecision = $this->withdrawalPolicy
                ->directWithdrawalDecision($actor, $booking);

            $capabilities['can_edit'] = $inRevision && ! $pendingCancellation;
            $capabilities['can_resubmit'] = $inRevision
                && ! $booking->isExpired()
                && ! $pendingCancellation;
            $capabilities['can_withdraw'] = $withdrawalDecision['allowed'];
            // Deprecated compatibility output: can_cancel has exactly one
            // meaning in C7B2, direct withdrawal eligibility.
            $capabilities['can_cancel'] = $capabilities['can_withdraw'];
            $capabilities['can_request_cancellation'] = $this->withdrawalPolicy
                ->canRequestCancellation($actor, $booking);
            $capabilities['can_withdraw_cancellation_request'] = $this->withdrawalPolicy
                ->canWithdrawCancellationRequest($actor, $booking);
            $capabilities['withdrawal_block_reason'] = $withdrawalDecision['block_reason'];
            $capabilities['next_action'] = $this->withdrawalPolicy->nextAction($actor, $booking);
        }

        $isDecisionReviewer = $this->reviewerResolver->canActAsApprover($actor, $booking);
        if ($isDecisionReviewer) {
            $pendingCancellation = $booking->hasPendingCancellationRequest();
            $submitted = $booking->status === RoomBookingStatus::Submitted;
            $expiredRevision = $booking->status === RoomBookingStatus::RevisionRequested
                && $booking->isExpired();
            $future = $booking->start_at !== null
                && $booking->start_at->greaterThan(now(config('app.timezone')));

            $capabilities['can_review'] = $submitted || $expiredRevision;
            $capabilities['can_start_review'] = $submitted
                && ! $booking->isExpired()
                && $booking->review_started_at === null
                && ! $pendingCancellation;
            $capabilities['can_approve'] = $submitted
                && ! $booking->isExpired()
                && ! $pendingCancellation;
            $capabilities['can_request_revision'] = $submitted
                && ! $booking->isExpired()
                && ! $pendingCancellation;
            $capabilities['can_reject'] = ($submitted || $expiredRevision)
                && ! $pendingCancellation;
            $capabilities['can_decide_cancellation'] = $pendingCancellation && $future;

            if ($capabilities['can_decide_cancellation']) {
                $capabilities['next_action'] = 'decide_cancellation';
            } elseif ($capabilities['can_start_review']) {
                $capabilities['next_action'] = 'start_review';
            } elseif ($capabilities['can_review']) {
                $capabilities['next_action'] = 'review';
            }
        }

        return $capabilities;
    }

    /**
     * Single policy source for surat-peminjaman read access, shared by the
     * attachment endpoints and the capability projection.
     */
    public function canViewAttachment(?User $actor, RoomBookingRequest $booking): bool
    {
        if (! $actor) {
            return false;
        }

        if ($actor->role === 'super_admin') {
            return true;
        }

        if ($actor->role === 'mahasiswa') {
            return (int) $booking->requester_id === (int) $actor->id;
        }

        if ($actor->role === 'tendik') {
            return $this->reviewerResolver->canRead($actor, $booking);
        }

        return false;
    }
}
