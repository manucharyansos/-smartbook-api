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
        $phone = $this->normalizePhone($phone);

        return ClientAccount::query()
            ->when($email || $phone, function ($q) use ($email, $phone) {
                $q->where(function ($inner) use ($email, $phone) {
                    if ($email) {
                        $inner->orWhere('email', $email);
                    }
                    if ($phone) {
                        $inner->orWhere('phone', $phone);
                    }
                });
            })
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
        $email = $this->normalizeEmail($account->email);
        $phone = $this->normalizePhone($account->phone);

        if (!$email && !$phone) {
            return 0;
        }

        return Client::query()
            ->where(function ($q) use ($email, $phone) {
                if ($email) {
                    $q->orWhere('email', $email);
                }
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->where(function ($q) use ($account) {
                $q->whereNull('client_account_id')
                    ->orWhere('client_account_id', $account->id);
            })
            ->update(['client_account_id' => $account->id]);
    }
}
