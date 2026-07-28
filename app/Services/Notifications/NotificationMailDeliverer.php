<?php

namespace App\Services\Notifications;

use App\Mail\AppNotificationMail;
use App\Models\AppNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Unified email delivery for the C7N1 backbone (C7N5). Email is a channel of the
 * single notification source of truth — a durable `app_notifications` row is
 * created first, and an ELIGIBLE one additionally queues an email to its
 * recipient. Best-effort and fully failure-isolated: a mail fault never breaks
 * or 500s the workflow, exactly like the in-app projection contract.
 *
 * Eligibility is deliberately narrow (action items + important outcomes) so the
 * product does not spam every reminder/update to email; it is config-tunable.
 */
class NotificationMailDeliverer
{
    /** Queue an email for the notification if the delivery policy allows it. */
    public function deliver(AppNotification $notification): void
    {
        if (! (bool) config('notifications.mail.enabled', true)) {
            return;
        }
        if (! $this->isEligible($notification)) {
            return;
        }

        $recipient = $notification->recipient;
        $email = $recipient?->email;
        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->queue(new AppNotificationMail(
                notificationTitle: $notification->title,
                notificationBody: $notification->body,
                actionLabel: $notification->action_label,
            ));
        } catch (\Throwable $e) {
            // Email is best-effort; the durable in-app notification already
            // exists. Never let a mail transport/config fault surface.
            Log::warning('Notification email dispatch failed', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Email the "you must act" family (action_required) plus important outcomes
     * (update at high/urgent). Reminders and system-health stay in-app only.
     */
    public function isEligible(AppNotification $notification): bool
    {
        $category = $notification->category->value;
        $priority = $notification->priority->value;

        if (in_array($category, (array) config('notifications.mail.categories', ['action_required']), true)) {
            return true;
        }

        return $category === 'update'
            && in_array($priority, (array) config('notifications.mail.update_priorities', ['urgent', 'high']), true);
    }
}
