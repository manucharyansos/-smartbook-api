<?php

namespace App\Services;

use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SmsService
{
    public function send(string $to, string $message, string $channel = 'sms'): array
    {
        $driver = (string) config('services.sms.driver', 'log');
        $normalizedTo = PhoneNormalizer::normalize($to) ?: $to;

        if ($driver === 'log') {
            Log::info('[reminders:log-delivery]', [
                'channel' => $channel,
                'to' => $normalizedTo,
                'message' => $message,
            ]);

            return [
                'provider' => 'log',
                'status' => 'delivered',
                'recipient' => $normalizedTo,
                'payload' => [
                    'mode' => 'log',
                    'channel' => $channel,
                ],
            ];
        }

        if ($driver === 'twilio') {
            /** @var TwilioService $twilio */
            $twilio = app(TwilioService::class);

            $response = $channel === 'whatsapp'
                ? $twilio->sendWhatsApp($normalizedTo, $message)
                : $twilio->sendSMS($normalizedTo, $message);

            return [
                'provider' => 'twilio',
                'status' => 'delivered',
                'recipient' => $normalizedTo,
                'payload' => [
                    'mode' => 'twilio',
                    'channel' => $channel,
                    'sid' => $response['sid'] ?? null,
                    'status' => $response['status'] ?? null,
                    'raw' => $response,
                ],
            ];
        }

        throw new RuntimeException("Unsupported SMS driver [{$driver}].");
    }
}
