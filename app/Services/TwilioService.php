<?php

namespace App\Services;

use App\Support\PhoneNormalizer;
use RuntimeException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

class TwilioService
{
    protected Client $client;

    public function __construct()
    {
        $sid = (string) env('TWILIO_SID');
        $token = (string) env('TWILIO_TOKEN');

        if ($sid === '' || $token === '') {
            throw new RuntimeException('Twilio credentials are not configured.');
        }

        $this->client = new Client($sid, $token);
    }

    public function sendSMS(string $to, string $message): array
    {
        $from = (string) (env('TWILIO_FROM') ?: config('services.sms.from'));
        if ($from === '') {
            throw new RuntimeException('TWILIO_FROM is not configured.');
        }

        return $this->sendMessage(
            to: PhoneNormalizer::normalize($to) ?: $to,
            from: $from,
            body: $message,
            channel: 'sms',
        );
    }

    public function sendWhatsApp(string $to, string $message): array
    {
        $from = (string) (env('TWILIO_WHATSAPP_FROM') ?: config('services.sms.whatsapp_from'));
        if ($from === '') {
            throw new RuntimeException('TWILIO_WHATSAPP_FROM is not configured.');
        }

        $normalizedTo = PhoneNormalizer::normalize($to) ?: $to;
        $toValue = str_starts_with($normalizedTo, 'whatsapp:') ? $normalizedTo : "whatsapp:{$normalizedTo}";

        return $this->sendMessage(
            to: $toValue,
            from: $from,
            body: $message,
            channel: 'whatsapp',
        );
    }

    protected function sendMessage(string $to, string $from, string $body, string $channel): array
    {
        try {
            $message = $this->client->messages->create($to, [
                'from' => $from,
                'body' => $body,
            ]);

            return [
                'sid' => $message->sid ?? null,
                'status' => $message->status ?? null,
                'channel' => $channel,
                'to' => $to,
                'from' => $from,
            ];
        } catch (TwilioException $e) {
            throw new RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
