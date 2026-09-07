<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class NewBookingNotification extends Notification
{
    use Queueable;

    public function __construct(public $booking) {}

    public function via($notifiable)
    {
        return ['mail', ExpoPushChannel::class];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Նոր ամրագրում')
            ->line('Դուք ունեք նոր ամրագրում.')
            ->line('Հաճախորդ: ' . $this->booking->client_name)
            ->line('Ամսաթիվ: ' . $this->booking->starts_at)
            ->action('Բացել օրացույցը', rtrim((string) config('services.public_booking.frontend_url', 'https://vizit.am'), '/') . '/app/calendar');
    }

    public function toExpoPush($notifiable): array
    {
        return [
            'title' => 'Նոր ամրագրում',
            'body' => ($this->booking->client_name ?: 'Հաճախորդ') . ' · ' . ($this->booking->starts_at?->format('d.m H:i') ?: ''),
            'data' => [
                'type' => 'booking.created',
                'audience' => 'business',
                'booking_id' => $this->booking->id,
                'booking_code' => $this->booking->booking_code,
            ],
        ];
    }
}
