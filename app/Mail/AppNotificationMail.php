<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The single email representation of a durable C7N1 notification. Rendered from
 * the same allowlisted, recipient-safe fields the API exposes (title, body,
 * optional action label) — never private metadata, storage paths, or internal
 * ids. Queued and `afterCommit` so it is dispatched only once the workflow that
 * produced the notification has committed (a rolled-back mutation sends nothing).
 */
class AppNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $notificationTitle,
        public string $notificationBody,
        public ?string $actionLabel = null,
    ) {
        // Dispatch only after the surrounding DB transaction commits, so a
        // rolled-back workflow sends no email (the Queueable trait owns the
        // $afterCommit property; we set it via its fluent helper).
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notificationTitle);
    }

    public function content(): Content
    {
        $title = e($this->notificationTitle);
        $body = e($this->notificationBody);
        $appUrl = e((string) config('app.url'));
        $cta = $this->actionLabel
            ? '<p style="margin:16px 0 0"><strong>Tindakan: '.e($this->actionLabel).'</strong></p>'
            : '';

        // Inline HTML (no blade view dependency). The email points to the app —
        // the in-app notification center holds the correct deep link, which
        // re-authorizes the recipient on arrival; emails never carry a
        // pre-authorized deep URL.
        $html = <<<HTML
            <div style="font-family:Arial,Helvetica,sans-serif;color:#111827;max-width:560px;margin:0 auto">
                <h2 style="font-size:18px;margin:0 0 8px">{$title}</h2>
                <p style="font-size:14px;line-height:1.6;color:#374151;margin:0">{$body}</p>
                {$cta}
                <p style="margin:20px 0 0">
                    <a href="{$appUrl}" style="display:inline-block;background:#0d4a46;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:bold">Buka Aplikasi</a>
                </p>
                <p style="font-size:12px;color:#9ca3af;margin:24px 0 0">Notifikasi DTEDI LMS. Buka pusat notifikasi di aplikasi untuk detail dan tindak lanjut.</p>
            </div>
            HTML;

        return new Content(htmlString: $html);
    }
}
