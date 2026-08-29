<?php

namespace App\Services;

use App\Models\Client;
use App\Models\MarketingCampaign;
use App\Models\MarketingDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MarketingCampaignService
{
    public function recipientQuery(MarketingCampaign $campaign): Builder
    {
        $query = Client::query()
            ->where('business_id', $campaign->business_id)
            ->where('marketing_opt_in', true)
            ->whereNull('marketing_unsubscribed_at')
            ->whereNotNull('email')
            ->where('email', '!=', '');

        return match ($campaign->segment) {
            'new' => $query->has('bookings', '<=', 1),
            'returning' => $query->has('bookings', '>=', 2),
            'inactive' => $query->whereDoesntHave('bookings', fn ($booking) => $booking
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->where('starts_at', '>=', now()->subDays(90))),
            'vip' => $query->where('is_vip', true),
            default => $query,
        };
    }

    public function recipientCount(MarketingCampaign $campaign): int
    {
        return (int) $this->recipientQuery($campaign)->count();
    }

    public function dispatch(MarketingCampaign $campaign): MarketingCampaign
    {
        if (in_array($campaign->status, ['sending', 'sent', 'cancelled'], true)) {
            return $campaign;
        }

        $claimed = MarketingCampaign::query()
            ->whereKey($campaign->id)
            ->whereIn('status', ['draft', 'scheduled', 'failed'])
            ->update([
                'status' => 'sending',
                'started_at' => now(),
                'completed_at' => null,
                'last_error' => null,
            ]);
        if ($claimed !== 1) {
            return $campaign->fresh();
        }
        $campaign->refresh();

        try {
            $clients = $this->recipientQuery($campaign)->orderBy('id')->limit(5000)->get();
            $campaign->update(['recipient_count' => $clients->count()]);

            foreach ($clients as $client) {
                $email = mb_strtolower(trim((string) $client->email));
                if ($email === '') continue;

                $delivery = MarketingDelivery::query()->firstOrCreate(
                    ['campaign_id' => $campaign->id, 'email' => $email],
                    [
                        'business_id' => $campaign->business_id,
                        'client_id' => $client->id,
                        'status' => 'pending',
                    ]
                );
                if ($delivery->status === 'sent') continue;

                $token = Str::random(48);
                $delivery->update([
                    'client_id' => $client->id,
                    'unsubscribe_token_hash' => hash('sha256', $token),
                    'status' => 'pending',
                    'error' => null,
                ]);

                try {
                    $this->sendEmail($campaign, $delivery, $client->name, $token);
                    $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
                } catch (\Throwable $exception) {
                    $delivery->update(['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 2000)]);
                    Log::warning('Marketing email delivery failed', [
                        'campaign_id' => $campaign->id,
                        'delivery_id' => $delivery->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $sent = MarketingDelivery::query()->where('campaign_id', $campaign->id)->where('status', 'sent')->count();
            $failed = MarketingDelivery::query()->where('campaign_id', $campaign->id)->where('status', 'failed')->count();
            $campaign->update([
                'status' => 'sent',
                'completed_at' => now(),
                'sent_count' => $sent,
                'failed_count' => $failed,
            ]);
        } catch (\Throwable $exception) {
            $campaign->update(['status' => 'failed', 'last_error' => Str::limit($exception->getMessage(), 4000)]);
            throw $exception;
        }

        return $campaign->fresh();
    }

    public function unsubscribe(MarketingDelivery $delivery, string $token): Client
    {
        abort_unless(
            $token !== '' && $delivery->unsubscribe_token_hash
            && hash_equals($delivery->unsubscribe_token_hash, hash('sha256', $token)),
            404
        );

        return DB::transaction(function () use ($delivery) {
            $client = Client::query()->lockForUpdate()->findOrFail($delivery->client_id);
            $client->update([
                'marketing_opt_in' => false,
                'marketing_unsubscribed_at' => now(),
            ]);
            return $client;
        });
    }

    private function sendEmail(MarketingCampaign $campaign, MarketingDelivery $delivery, ?string $clientName, string $token): void
    {
        $campaign->loadMissing('business');
        $unsubscribeUrl = rtrim((string) config('services.public_booking.frontend_url', 'https://vizit.am'), '/')
            . '/marketing/unsubscribe?delivery=' . $delivery->id
            . '&token=' . rawurlencode($token);
        $body = str_replace(['{{name}}', '{{business}}'], [$clientName ?: '', $campaign->business?->name ?: 'Vizit'], $campaign->body);
        $body .= "\n\n—\nԱյլևս չստանալու համար՝ " . $unsubscribeUrl;

        Mail::raw($body, function ($message) use ($campaign, $delivery) {
            $message->to($delivery->email)->subject($campaign->subject);
        });
    }
}
