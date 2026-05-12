<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScholarshipSubmittedNotification extends Notification
{
    use Queueable;

    protected $application;

    /**
     * Create a new notification instance.
     */
    public function __construct($application)
    {
        $this->application = $application;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/tendik/dashboard/surat-permohonan-beasiswa/' . $this->application->id);
        
        return (new MailMessage)
            ->subject('Pengajuan Beasiswa Baru: ' . $this->application->scholarship_name)
            ->greeting('Halo, ' . $notifiable->name)
            ->line('Ada pengajuan beasiswa baru yang masuk dan telah ditugaskan kepada Anda.')
            ->line('Nama Mahasiswa: ' . ($this->application->mahasiswaProfile->nama_lengkap ?? ($this->application->user->name ?? '-')))
            ->line('NIM: ' . $this->application->nim)
            ->action('Lihat Detail Pengajuan', $url)
            ->line('Harap segera memeriksa dan memberikan persetujuan pada dokumen yang telah digenerate.');
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
            'mahasiswa_name' => $this->application->mahasiswaProfile->nama_lengkap ?? ($this->application->user->name ?? '-'),
            'scholarship_name' => $this->application->scholarship_name,
            'message' => 'Pengajuan beasiswa baru dari ' . ($this->application->mahasiswaProfile->nama_lengkap ?? ($this->application->user->name ?? '-')) . ' telah ditugaskan kepada Anda.',
            'action_url' => '/tendik/dashboard/surat-permohonan-beasiswa/' . $this->application->id,
        ];
    }
}
