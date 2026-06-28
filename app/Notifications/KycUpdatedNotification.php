<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Kyc;
use App\Models\Translation;

class KycUpdatedNotification extends Notification implements ShouldQueue
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
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $this->kyc->loadMissing('user');

        return (new MailMessage)
            ->subject(Translation::getText('notifications.kyc.updated', 'en', [], 'value_short'))
            ->line(Translation::getText('notifications.kyc.updated', 'en', ['name' => $this->kyc->user->name]))
            ->action(Translation::getText('notifications.kyc.show', 'en'), url(route('admin.kyc.show', ['kyc' => $this->kyc->id])))
            ->salutation(Translation::getText('salutation', 'en', ['app_name' => config('app.name')]));
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
            'message'   => Translation::getText('notifications.kyc.updated', 'en', ['name' => $this->kyc->user->name]),
            'action_url' => url(route('admin.kyc.show', ['kyc' => $this->kyc->id])),
        ];
    }
}
