<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ConfigureTelegramWebhook extends Command
{
    protected $signature = 'telegram:configure-webhook';
    protected $description = 'Register the Vizit Telegram bot webhook';

    public function handle(): int
    {
        $token = trim((string) config('services.telegram.bot_token'));
        $url = trim((string) config('services.telegram.webhook_url'));
        $secret = trim((string) config('services.telegram.webhook_secret'));

        if ($token === '' || $url === '') {
            $this->error('TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_URL must be configured.');
            return self::FAILURE;
        }

        $payload = [
            'url' => $url,
            'allowed_updates' => ['message'],
            'drop_pending_updates' => false,
        ];
        if ($secret !== '') {
            $payload['secret_token'] = $secret;
        }

        $response = Http::timeout(12)
            ->retry(2, 300)
            ->post("https://api.telegram.org/bot{$token}/setWebhook", $payload);

        if (!$response->successful() || !$response->json('ok')) {
            $this->error('Telegram rejected the webhook configuration: ' . ($response->json('description') ?: $response->status()));
            return self::FAILURE;
        }

        $this->info('Telegram webhook configured successfully.');
        return self::SUCCESS;
    }
}
