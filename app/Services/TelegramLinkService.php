<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramLinkService
{
    private const CACHE_PREFIX = 'telegram:connect:';
    private const START_PREFIX = 'vizit_';

    public function __construct(private readonly TelegramService $telegram)
    {
    }

    /**
     * @return array{url:string,expires_at:string}
     */
    public function issueForUser(User $user): array
    {
        return $this->issue('user', (int) $user->id);
    }

    /**
     * @return array{url:string,expires_at:string}
     */
    public function issueForClient(Client $client): array
    {
        return $this->issue('client', (int) $client->id);
    }

    /**
     * @return array{type:'user'|'client',id:int}|null
     */
    public function consume(string $startPayload): ?array
    {
        if (!str_starts_with($startPayload, self::START_PREFIX)) {
            return null;
        }

        $token = substr($startPayload, strlen(self::START_PREFIX));
        if (!preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        $payload = Cache::pull(self::CACHE_PREFIX . $token);
        if (!is_array($payload) || !in_array($payload['type'] ?? null, ['user', 'client'], true)) {
            return null;
        }

        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            return null;
        }

        return ['type' => $payload['type'], 'id' => (int) $id];
    }

    /**
     * @return array{url:string,expires_at:string}
     */
    private function issue(string $type, int $id): array
    {
        $token = Str::random(40);
        $expiresAt = Carbon::now()->addMinutes((int) config('services.telegram.link_ttl_minutes', 15));
        $startPayload = self::START_PREFIX . $token;
        $url = $this->telegram->botUrl($startPayload);

        if (!$url) {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        Cache::put(self::CACHE_PREFIX . $token, [
            'type' => $type,
            'id' => $id,
        ], $expiresAt);

        return [
            'url' => $url,
            'expires_at' => $expiresAt->toISOString(),
        ];
    }
}
