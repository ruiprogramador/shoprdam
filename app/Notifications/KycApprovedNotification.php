<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Kyc;

class KycApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public readonly Kyc $kyc)
    {
        //
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
        return (new MailMessage)
            ->subject(__('notifications.kyc.approved.subject'))
            ->greeting(__('notifications.kyc.approved.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.kyc.approved.message'))
            ->line(__('notifications.kyc.approved.expiration', ['date' => $this->kyc->expires_at->format('d/m/Y')]))
            ->action(__('notifications.kyc.approved.action'), url('/dashboard'))
            ->salutation(__('notifications.kyc.salutation'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kyc_id'  => $this->kyc->id,
            'message' => __('notifications.kyc.approved.message'),
        ];
    }
}
