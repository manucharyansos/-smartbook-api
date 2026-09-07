<?php

namespace App\Jobs;

use App\Models\MobileDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendExpoPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 20;

    public function __construct(
        public string $ownerType,
        public int $ownerId,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $devices = MobileDevice::query()->where('owner_type', $this->ownerType)
            ->where('owner_id', $this->ownerId)->whereNull('disabled_at')->get();
        if ($devices->isEmpty()) return;

        $messages = $devices->map(fn (MobileDevice $device) => [
            'to' => $device->expo_push_token,
            'sound' => 'default',
            'channelId' => 'bookings',
            'title' => (string) ($this->payload['title'] ?? 'Vizit'),
            'body' => (string) ($this->payload['body'] ?? ''),
            'data' => (array) ($this->payload['data'] ?? []),
        ])->values()->all();

        $request = Http::acceptJson()->asJson()->timeout(12);
        $accessToken = trim((string) config('services.expo.access_token'));
        if ($accessToken !== '') $request = $request->withToken($accessToken);
        $response = $request->post((string) config('services.expo.push_url'), $messages);
        $response->throw();

        $tickets = $response->json('data', []);
        foreach ($devices->values() as $index => $device) {
            $error = data_get($tickets, $index . '.details.error');
            if ($error === 'DeviceNotRegistered') {
                $device->update(['disabled_at' => now()]);
            }
        }

        Log::info('Expo push delivery completed', [
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
            'device_count' => $devices->count(),
            'event' => data_get($this->payload, 'data.type'),
        ]);
    }
}
