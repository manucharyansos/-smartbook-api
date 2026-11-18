<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Rate your visit" email with a one-booking review link. Sent only when reviews.request_emails is on. */
class ReviewRequestNotification extends Notification
{
    public function __construct(public Booking $booking, public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $business = $this->booking->business?->name ?? 'Vizit';
        $url = rtrim((string) config('app.frontend_url'), '/').'/review/'.rawurlencode((string) $this->booking->booking_code)
            .'?booking_id='.$this->booking->id.'&rt='.rawurlencode($this->token);

        return (new MailMessage)
            ->subject("Ինչպե՞ս անցավ այցը — {$business}")
            ->greeting('Բարև Ձեզ։')
            ->line("Շնորհակալություն, որ ընտրեցիք {$business}-ը։ Մի քանի վայրկյանում գնահատեք այցը․ Ձեր կարծիքը կօգնի ուրիշներին ընտրել։")
            ->action('Գնահատել այցը', $url)
            ->line('Как прошёл визит? Оцените его по ссылке выше. · How was your visit? Rate it using the link above.')
            ->line('Հղումը գործում է '.config('reviews.window_days', 30).' օր։');
    }
}
