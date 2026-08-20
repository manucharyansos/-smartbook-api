<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;

class ClientVerifyEmail extends VerifyEmail
{
    protected function verificationUrl($notifiable): string
    {
        $apiVerificationUrl = parent::verificationUrl($notifiable);
        $query = (string) parse_url($apiVerificationUrl, PHP_URL_QUERY);
        $frontend = rtrim((string) config('app.frontend_url', 'https://vizit.am'), '/');
        $id = rawurlencode((string) $notifiable->getKey());
        $hash = rawurlencode(sha1($notifiable->getEmailForVerification()));

        return "{$frontend}/client/verify-email/{$id}/{$hash}".($query !== '' ? "?{$query}" : '');
    }
}
