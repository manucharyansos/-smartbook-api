<?php

namespace App\Notifications;

use App\Models\ClientReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientReminderMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ClientReminder $reminder)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->reminder->client;
        $businessName = $client?->business?->name ?? 'SOS';

        return (new MailMessage)
            ->subject("Reminder — {$businessName}")
            ->greeting('Hello!')
            ->line($this->reminder->title)
            ->line($this->reminder->note ?: 'You have a scheduled reminder from the business.')
            ->line('Time: ' . optional($this->reminder->remind_at)?->format('Y-m-d H:i'));
    }
}
