<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Kyc;
use App\Models\Translation;

class KycExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Kyc $kyc) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(Translation::getText('notifications.kyc.expired.subject', 'en', ['kyc_id' => $this->kyc->id]))
            ->greeting(Translation::getText('notifications.greeting', 'en', ['name' => $this->kyc->user->name], 'value'))
            ->line(Translation::getText('notifications.kyc.expired.message', 'en', ['kyc_id' => $this->kyc->id]))
            ->action(Translation::getText('notifications.kyc.show', 'en'), url(route('vendor.kyc.show', $this->kyc)));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kyc_id'  => $this->kyc->id,
            'message' => Translation::getText('notifications.kyc.expired.message', 'en'),
            'action_url' => url(route('vendor.kyc.show', $this->kyc)),
        ];
    }
}