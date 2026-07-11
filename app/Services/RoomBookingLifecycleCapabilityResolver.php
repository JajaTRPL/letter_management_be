<?php

namespace App\Services;

use App\Enums\RoomBookingStatus;
use App\Models\RoomBookingRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

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
    ) {}

    /**
     * @return array<string, bool>
     */
    public function capabilitiesFor(?User $actor, RoomBookingRequest $booking): array
    {
        $capabilities = [
            'can_edit' => false,
            'can_resubmit' => false,
            'can_cancel' => false,
            'can_review' => false,
            'can_approve' => false,
            'can_request_revision' => false,
            'can_reject' => false,
            'can_view_attachment' => false,
        ];

        if (! $actor) {
            return $capabilities;
        }

        $capabilities['can_view_attachment'] = $this->canViewAttachment($actor, $booking);

        // Completed bookings (approved + activity ended) carry no workflow
        // mutation capabilities at all.
        if ($booking->isCompleted()) {
            return $capabilities;
        }

        $isOwner = $actor->role === 'mahasiswa'
            && (int) $booking->requester_id === (int) $actor->id;

        if ($isOwner) {
            $inRevision = $booking->status === RoomBookingStatus::RevisionRequested;
            $capabilities['can_edit'] = $inRevision;
            // Parity with the resubmit endpoint: an expired schedule is
            // deterministically rejected (future-start validation), so the
            // capability must not advertise it. Editing stays available so
            // the applicant can move the schedule to a future date first.
            $capabilities['can_resubmit'] = $inRevision && ! $booking->isExpired();
            // Legacy immediate-cancel compatibility: submitted/revision any
            // time, approved only before the activity starts.
            $capabilities['can_cancel'] = in_array($booking->status, [
                RoomBookingStatus::Submitted,
                RoomBookingStatus::RevisionRequested,
            ], true)
                || (
                    $booking->status === RoomBookingStatus::Approved
                    && $booking->start_at !== null
                    && $booking->start_at->greaterThan(Carbon::now(config('app.timezone')))
                );
        }

        $canAct = $booking->status === RoomBookingStatus::Submitted
            && $this->reviewerResolver->canActAsApprover($actor, $booking);

        $capabilities['can_review'] = $canAct;
        // Past-start pending requests can no longer be approved (guarded in
        // the transition service); revision/rejection remain available so a
        // reviewer can still close the loop explicitly.
        $capabilities['can_approve'] = $canAct && ! $booking->isExpired();
        $capabilities['can_request_revision'] = $canAct;
        $capabilities['can_reject'] = $canAct;

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
