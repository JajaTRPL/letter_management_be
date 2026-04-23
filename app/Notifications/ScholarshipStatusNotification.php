<?php

namespace App\Notifications;

use App\Models\ScholarshipApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScholarshipStatusNotification extends Notification
{
    use Queueable;

    protected $application;
    protected $message;

    /**
     * Create a new notification instance.
     */
    public function __construct(ScholarshipApplication $application, $message)
    {
        $this->application = $application;
        $this->message = $message;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                    ->subject('Update Status Beasiswa - ' . $this->application->scholarship_name)
                    ->line($this->message)
                    ->line('Nama Mahasiswa: ' . ($this->application->mahasiswaProfile->nama_lengkap ?? $this->application->user->name))
                    ->action('Lihat Dashboard', url('/'))
                    ->line('Terima kasih telah menggunakan sistem kami!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'scholarship_name' => $this->application->scholarship_name,
            'message' => $this->message,
            'status' => $this->application->status,
            'student_name' => $this->application->mahasiswaProfile->nama_lengkap ?? $this->application->user->name,
        ];
    }
}
