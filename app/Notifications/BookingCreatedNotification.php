<?php
// app/Notifications/BookingCreatedNotification.php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class BookingCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public Business $business,
        public Service $service,
        public ?User $staff = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $b = $this->booking;

        return (new MailMessage)
            ->subject("New booking request — {$this->business->name}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("A new booking request was created (status: {$b->status}).")
            ->line("Client: {$b->client_name} ({$b->client_phone})")
            ->line("Service: {$this->service->name}")
            ->line("Time: {$b->starts_at} — {$b->ends_at}")
            ->line("Code: {$b->booking_code}") // Փոխել code-ից booking_code
            ->action("Open Dashboard", config('app.frontend_url') . "/app/calendar")
            ->line("Notes: " . ($b->notes ?? "—"));
    }
}
