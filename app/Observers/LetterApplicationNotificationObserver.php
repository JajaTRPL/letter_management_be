<?php

namespace App\Observers;

use App\Services\Notifications\NotificationProjector;
use App\Support\LetterWorkflowStatus as LS;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared status-transition seam for the five administrative letter types. They
 * all carry the same LetterWorkflowStatus vocabulary and a `user()` applicant
 * relation, so one observer projects the whole applicant + academic matrix from
 * their persisted status changes.
 *
 * Fires synchronously on the model's `updated`/`created` events, which occur
 * inside each controller's mutation transaction — so the notification is atomic
 * with the transition (a rollback discards both), and the projector isolates
 * its own failures so a notification defect never 500s a successful workflow.
 *
 * The Persuratan queue item is emitted separately from the assignment seam
 * (LetterAssignmentService), which knows the concrete assignee; this observer
 * owns applicant + academic notifications and resolution only.
 */
class LetterApplicationNotificationObserver
{
    public function __construct(private NotificationProjector $projector) {}

    public function created(Model $application): void
    {
        // A letter that is created already in the Submitted state (no prior
        // draft row) is a first submission transition.
        if ($application->getAttribute('status') === LS::SUBMITTED) {
            $this->projector->projectLetterTransition(
                $application,
                $application::LETTER_TYPE,
                null,
                LS::SUBMITTED,
            );
        }
    }

    public function updated(Model $application): void
    {
        if (! $application->wasChanged('status')) {
            return;
        }

        $this->projector->projectLetterTransition(
            $application,
            $application::LETTER_TYPE,
            $application->getOriginal('status'),
            $application->getAttribute('status'),
        );
    }
}
