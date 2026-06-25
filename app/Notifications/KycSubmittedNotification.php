<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Kyc;
use App\Models\Translation;

class KycSubmittedNotification extends Notification implements ShouldQueue
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
            ->subject(Translation::getText('notifications.kyc.submitted', 'en'))
            ->line(Translation::getText('notifications.kyc.submitted.message', 'en', ['name' => $this->kyc->user->name]))
            ->action('Ver KYC', url(route('admin.kyc.show', $this->kyc)))
            ->line('Obrigado!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    /* public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    } */

    public function toDatabase(object $notifiable): array
    {
        $this->kyc->loadMissing('user');

        return [
            'kyc_id'    => $this->kyc->id,
            'vendor'    => $this->kyc->user->name,
            'message'   => __('notifications.kyc.submitted.message', ['name' => $this->kyc->user->name]),
        ];
    }
}
