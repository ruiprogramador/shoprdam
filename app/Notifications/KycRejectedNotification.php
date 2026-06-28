<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Kyc;
use App\Models\Translation;

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
            ->subject(Translation::getText('notifications.kyc.rejected.subject', 'en'))
            ->greeting(Translation::getText('notifications.greeting', 'en', ['name' => $this->kyc->user->name], 'value'))
            ->line(Translation::getText('notifications.kyc.rejected.message', 'en'))
            ->lineIf(
                filled($this->kyc->rejection_reason),
                Translation::getText('notifications.kyc.rejected.reason', 'en', ['reason' => $this->kyc->rejection_reason])
            )
            ->line(Translation::getText('notifications.kyc.rejected.warning', 'en'))
            ->action(Translation::getText('notifications.kyc.rejected.action', 'en'), url(route('vendor.kyc.edit', ['kyc' => $this->kyc->id])) )
            ->salutation(Translation::getText('salutation', 'en', ['app_name' => config('app.name')]));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kyc_id'           => $this->kyc->id,
            'message'          => Translation::getText('notifications.kyc.rejected.message', 'en'),
            'rejection_reason' => $this->kyc->rejection_reason,
            'action_url'       => url(route('vendor.kyc.edit', ['kyc' => $this->kyc->id])),
        ];
    }
}
