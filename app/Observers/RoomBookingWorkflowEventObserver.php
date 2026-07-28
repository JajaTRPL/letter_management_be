<?php

namespace App\Observers;

use App\Models\RoomBookingWorkflowEvent;
use App\Services\Notifications\NotificationProjector;

/**
 * Bridges the immutable room-booking workflow ledger to the notification
 * projection. Fires synchronously on `created`, so the projection runs inside
 * the same transaction as the authoritative mutation (atomic: no committed
 * event without its notification attempt, and RefreshDatabase tests observe it
 * without the afterCommit gotcha). The projector isolates its own failures, so
 * a notification defect never rolls back or 500s the workflow mutation.
 */
class RoomBookingWorkflowEventObserver
{
    public function __construct(private NotificationProjector $projector) {}

    public function created(RoomBookingWorkflowEvent $event): void
    {
        $this->projector->projectRoomBookingEvent($event);
    }
}
