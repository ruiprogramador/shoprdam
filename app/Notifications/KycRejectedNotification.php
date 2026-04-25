<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Kyc;

class KycRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public readonly Kyc $kyc) {}

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
        ->subject(__('notifications.kyc.rejected.subject'))
            ->greeting(__('notifications.kyc.rejected.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.kyc.rejected.message'))
            ->lineIf(
                filled($this->kyc->rejection_reason),
                __('notifications.kyc.rejected.reason', ['reason' => $this->kyc->rejection_reason])
            )
            ->line(__('notifications.kyc.rejected.warning'))
            ->action(__('notifications.kyc.rejected.action'), url('/kyc/edit'))
            ->salutation(__('notifications.kyc.salutation'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kyc_id'           => $this->kyc->id,
            'message'          => __('notifications.kyc.rejected.message'),
            'rejection_reason' => $this->kyc->rejection_reason,
        ];
    }
}
