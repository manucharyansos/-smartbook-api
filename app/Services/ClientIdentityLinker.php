<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Support\Phone;

class ClientIdentityLinker
{
    public function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        return $email !== '' ? mb_strtolower($email) : null;
    }

    public function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }

        return Phone::normalizeAM($phone) ?: $phone;
    }

    public function findMatchingAccount(?string $email, ?string $phone): ?ClientAccount
    {
        $email = $this->normalizeEmail($email);

        // Phone ownership is not considered verified until a dedicated phone
        // verification flow exists. Never link private booking history from a
        // phone number that was merely typed into the registration form.
        if (!$email) {
            return null;
        }

        return ClientAccount::query()
            ->whereNotNull('email_verified_at')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
    }

    public function linkClientProfile(Client $client): ?ClientAccount
    {
        $account = $this->findMatchingAccount($client->email, $client->phone);

        if ($account && (int) $client->client_account_id !== (int) $account->id) {
            $client->client_account_id = $account->id;
            $client->save();
        }

        return $account;
    }

    public function syncLinkedClients(ClientAccount $account): int
    {
        $email = $account->hasVerifiedEmail()
            ? $this->normalizeEmail($account->email)
            : null;

        if (!$email) {
            return 0;
        }

        return Client::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where(function ($q) use ($account) {
                $q->whereNull('client_account_id')
                    ->orWhere('client_account_id', $account->id);
            })
            ->update(['client_account_id' => $account->id]);
    }
}
