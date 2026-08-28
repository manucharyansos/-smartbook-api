<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class BookingRescheduledNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public Carbon $oldStart,
        public Carbon $oldEnd,
        public ?string $oldStaffName = null,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $this->booking->loadMissing(['business', 'service', 'staff', 'items.service']);
        $timezone = $this->booking->business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $oldTime = $this->oldStart->copy()->timezone($timezone)->format('d.m.Y H:i');
        $newTime = $this->booking->starts_at?->copy()->timezone($timezone)->format('d.m.Y H:i') ?? '—';
        $services = $this->booking->items->count()
            ? $this->booking->items->map(fn ($item) => $item->service?->name)->filter()->implode(', ')
            : ($this->booking->service?->name ?? 'Ծառայություն');

        return (new MailMessage)
            ->subject('Ամրագրման ժամը փոխվել է')
            ->line('Հաճախորդը փոխել է ամրագրման ժամը։')
            ->line('Հաճախորդ՝ ' . ($this->booking->client_name ?: '—'))
            ->line('Ծառայություն՝ ' . $services)
            ->line('Նախկին ժամ՝ ' . $oldTime)
            ->line('Նոր ժամ՝ ' . $newTime)
            ->line('Նախկին մասնագետ՝ ' . ($this->oldStaffName ?: '—'))
            ->line('Նոր մասնագետ՝ ' . ($this->booking->staff?->name ?? '—'))
            ->action(
                'Բացել օրացույցը',
                rtrim((string) config('services.public_booking.frontend_url', 'https://vizit.am'), '/') . '/app/calendar',
            );
    }
}
