<?php

namespace App\Services;

use App\Models\ClientReminder;
use App\Models\ClientReminderDelivery;
use App\Notifications\ClientReminderMailNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ClientReminderDispatchService
{
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
            ->filter(fn ($channel) => in_array((string) $channel, ['internal', 'email'], true))
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

        throw new \RuntimeException("Unsupported reminder delivery channel [{$channel}].");
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
            default => null,
        };
    }

    protected function providerFor(string $channel): ?string
    {
        return match ($channel) {
            'email' => 'laravel_mail',
            default => null,
        };
    }
}
