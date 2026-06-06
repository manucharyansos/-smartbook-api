<?php

namespace App\Services;

use App\Models\ClientReminder;
use App\Models\ClientReminderDelivery;
use App\Notifications\ClientReminderMailNotification;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ClientReminderDispatchService
{
    public function __construct(
        protected SmsService $smsService,
    ) {}

    public function isDue(ClientReminder $reminder, ?Carbon $now = null): bool
    {
        $now = $now ?: now();
        $scheduledAt = $reminder->remind_at instanceof Carbon
            ? $reminder->remind_at->copy()
            : Carbon::parse((string) $reminder->remind_at);

        $leadMinutes = max(0, (int) ($reminder->lead_minutes ?? 0));
        return $scheduledAt->copy()->subMinutes($leadMinutes)->lte($now);
    }

    public function dispatch(ClientReminder $reminder, bool $force = false): array
    {
        $reminder->loadMissing(['client.business', 'deliveries']);

        if (!$reminder->is_enabled) {
            return ['processed' => false, 'message' => 'Reminder disabled'];
        }

        if (!$force && !$this->isDue($reminder)) {
            return ['processed' => false, 'message' => 'Reminder not due yet'];
        }

        $channels = collect($reminder->notify_via ?: [$reminder->channel ?: 'internal'])
            ->filter()
            ->unique()
            ->values();

        $processed = [];

        foreach ($channels as $channel) {
            $channel = (string) $channel;
            $delivery = ClientReminderDelivery::firstOrCreate(
                [
                    'client_reminder_id' => $reminder->id,
                    'channel' => $channel,
                ],
                [
                    'status' => 'pending',
                    'scheduled_for' => $this->scheduledFor($reminder),
                    'recipient' => $this->recipientFor($reminder, $channel),
                    'provider' => $this->providerFor($channel),
                ]
            );

            if (in_array($delivery->status, ['delivered', 'queued'], true) && !$force) {
                $processed[] = $delivery;
                continue;
            }

            $delivery->scheduled_for = $this->scheduledFor($reminder);
            $delivery->recipient = $this->recipientFor($reminder, $channel);
            $delivery->provider = $this->providerFor($channel);
            $delivery->error_message = null;
            $delivery->failed_at = null;

            try {
                $this->deliver($reminder, $delivery, $channel);
            } catch (Throwable $e) {
                $delivery->status = 'failed';
                $delivery->failed_at = now();
                $delivery->error_message = $e->getMessage();
                $delivery->save();
            }

            $processed[] = $delivery->fresh();
        }

        $statuses = collect($processed)->pluck('status')->all();
        if (in_array('failed', $statuses, true)) {
            $reminder->status = 'queued';
            $reminder->completed_at = null;
        } elseif (in_array('queued', $statuses, true)) {
            $reminder->status = 'queued';
            $reminder->completed_at = null;
        } else {
            $reminder->status = 'done';
            $reminder->completed_at = now();
        }
        $reminder->save();

        return ['processed' => true, 'message' => 'Reminder dispatched', 'deliveries' => $processed];
    }

    protected function deliver(ClientReminder $reminder, ClientReminderDelivery $delivery, string $channel): void
    {
        if ($channel === 'internal') {
            $delivery->status = 'delivered';
            $delivery->sent_at = now();
            $delivery->payload = ['mode' => 'internal'];
            $delivery->save();
            return;
        }

        if ($channel === 'email') {
            $email = $reminder->client?->email;
            if (!$email) {
                $delivery->status = 'skipped';
                $delivery->error_message = 'Client email is missing';
                $delivery->save();
                return;
            }

            Notification::sendNow(
                Notification::route('mail', $email),
                new ClientReminderMailNotification($reminder)
            );
            $delivery->status = 'delivered';
            $delivery->sent_at = now();
            $delivery->payload = ['mode' => 'mail'];
            $delivery->save();
            return;
        }

        $phone = PhoneNormalizer::normalize($reminder->client?->phone);
        if (!$phone) {
            $delivery->status = 'skipped';
            $delivery->error_message = 'Client phone is missing';
            $delivery->save();
            return;
        }

        $message = $this->messageFor($reminder, $channel);
        $result = $this->smsService->send($phone, $message, $channel);

        $delivery->status = $result['status'] ?? 'delivered';
        $delivery->provider = $result['provider'] ?? $this->providerFor($channel);
        $delivery->recipient = $result['recipient'] ?? $phone;
        $delivery->sent_at = now();
        $delivery->payload = $result['payload'] ?? ['mode' => 'provider'];
        $delivery->save();
    }

    protected function scheduledFor(ClientReminder $reminder): ?Carbon
    {
        $scheduledAt = $reminder->remind_at instanceof Carbon
            ? $reminder->remind_at->copy()
            : Carbon::parse((string) $reminder->remind_at);

        return $scheduledAt->subMinutes(max(0, (int) ($reminder->lead_minutes ?? 0)));
    }

    protected function recipientFor(ClientReminder $reminder, string $channel): ?string
    {
        return match ($channel) {
            'email' => $reminder->client?->email,
            'sms', 'whatsapp' => PhoneNormalizer::normalize($reminder->client?->phone),
            default => null,
        };
    }

    protected function providerFor(string $channel): ?string
    {
        return match ($channel) {
            'email' => 'laravel_mail',
            'sms', 'whatsapp' => (string) config('services.sms.driver', 'log'),
            default => null,
        };
    }

    protected function messageFor(ClientReminder $reminder, string $channel): string
    {
        $businessName = $reminder->client?->business?->name ?: config('app.name', 'Vizit');
        $when = $reminder->remind_at instanceof Carbon
            ? $reminder->remind_at->format('d.m.Y H:i')
            : Carbon::parse((string) $reminder->remind_at)->format('d.m.Y H:i');

        $parts = [
            $businessName,
            $reminder->title ?: 'Appointment reminder',
            'Time: ' . $when,
        ];

        if ($reminder->note) {
            $parts[] = trim((string) $reminder->note);
        }

        return $channel === 'whatsapp'
            ? implode("\n", $parts)
            : implode(' | ', $parts);
    }
}
