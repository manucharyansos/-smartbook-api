<?php

namespace App\Notifications\Channels;

use App\Jobs\SendExpoPushNotification;
use Illuminate\Notifications\Notification;

class ExpoPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toExpoPush')) return;
        $payload = $notification->toExpoPush($notifiable);
        if (!$payload) return;

        SendExpoPushNotification::dispatch(
            $notifiable->getMorphClass(),
            (int) $notifiable->getKey(),
            $payload,
        );
    }
}
