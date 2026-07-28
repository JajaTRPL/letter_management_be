<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Mail\AppNotificationMail;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\Notifications\NotificationActionRoute;
use App\Services\Notifications\NotificationIntent;
use App\Services\Notifications\NotificationMailDeliverer;
use App\Services\Notifications\NotificationWriter;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Peminjaman\RoomBookingApiTestCase;

/**
 * Unified email delivery (C7N5): email is a channel of the single C7N1 backbone.
 * Creating an eligible durable notification also queues one email to its
 * recipient — once, best-effort, policy-scoped — replacing the retired
 * scholarship-only mail bridge with one uniform contract.
 */
class NotificationMailDeliveryTest extends RoomBookingApiTestCase
{
    private NotificationWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->writer = app(NotificationWriter::class);
    }

    public function test_an_action_required_notification_queues_one_email_to_the_recipient(): void
    {
        $user = $this->student();
        $this->writer->write($this->intent($user, 'k1', NotificationCategory::ActionRequired, NotificationPriority::High));

        Mail::assertQueued(AppNotificationMail::class, function (AppNotificationMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->notificationTitle === 'Perlu tindakan'
                && $mail->afterCommit === true; // sent only after the workflow commits
        });
    }

    public function test_important_update_outcomes_are_emailed_but_routine_ones_are_not(): void
    {
        $user = $this->student();
        // update/high (e.g. approved/rejected/ready) → emailed.
        $this->writer->write($this->intent($user, 'k-high', NotificationCategory::Update, NotificationPriority::High));
        // update/normal (routine) → not emailed.
        $this->writer->write($this->intent($user, 'k-normal', NotificationCategory::Update, NotificationPriority::Normal));

        Mail::assertQueued(AppNotificationMail::class, 1);
    }

    public function test_reminders_and_system_health_stay_in_app_only(): void
    {
        $user = $this->student();
        $this->writer->write($this->intent($user, 'k-rem', NotificationCategory::Reminder, NotificationPriority::High));
        $this->writer->write($this->intent($user, 'k-sys', NotificationCategory::System, NotificationPriority::Urgent));

        Mail::assertNothingQueued();
    }

    public function test_a_deduped_replay_does_not_re_send_email(): void
    {
        $user = $this->student();
        $intent = $this->intent($user, 'k-dup', NotificationCategory::ActionRequired, NotificationPriority::High);

        $this->writer->write($intent);
        $this->writer->write($intent); // same (recipient, dedup_key) → reuse, no new row
        $this->writer->write($intent);

        Mail::assertQueued(AppNotificationMail::class, 1);
    }

    public function test_delivery_can_be_disabled_by_config(): void
    {
        config(['notifications.mail.enabled' => false]);
        $user = $this->student();
        $this->writer->write($this->intent($user, 'k-off', NotificationCategory::ActionRequired, NotificationPriority::High));

        Mail::assertNothingQueued();
    }

    public function test_a_recipient_without_an_email_is_skipped_safely(): void
    {
        $user = $this->student();
        $user->forceFill(['email' => ''])->save();
        $this->writer->write($this->intent($user->fresh(), 'k-noemail', NotificationCategory::ActionRequired, NotificationPriority::High));

        Mail::assertNothingQueued();
    }

    public function test_eligibility_policy_is_explicit(): void
    {
        $deliverer = app(NotificationMailDeliverer::class);
        $make = fn (NotificationCategory $c, NotificationPriority $p) => new AppNotification([
            'category' => $c, 'priority' => $p,
        ]);

        $this->assertTrue($deliverer->isEligible($make(NotificationCategory::ActionRequired, NotificationPriority::Low)));
        $this->assertTrue($deliverer->isEligible($make(NotificationCategory::Update, NotificationPriority::Urgent)));
        $this->assertTrue($deliverer->isEligible($make(NotificationCategory::Update, NotificationPriority::High)));
        $this->assertFalse($deliverer->isEligible($make(NotificationCategory::Update, NotificationPriority::Normal)));
        $this->assertFalse($deliverer->isEligible($make(NotificationCategory::Reminder, NotificationPriority::High)));
        $this->assertFalse($deliverer->isEligible($make(NotificationCategory::System, NotificationPriority::Urgent)));
    }

    private function intent(
        User $recipient,
        string $dedup,
        NotificationCategory $category,
        NotificationPriority $priority,
    ): NotificationIntent {
        return new NotificationIntent(
            recipient: $recipient,
            eventType: 'test_event',
            category: $category,
            priority: $priority,
            title: 'Perlu tindakan',
            body: 'Sebuah item memerlukan perhatian.',
            dedupKey: $dedup,
            subjectType: 'test',
            subjectPublicId: '1',
            actionRouteKey: NotificationActionRoute::MAHASISWA_BOOKING_DETAIL,
            actionLabel: 'Buka',
        );
    }
}
