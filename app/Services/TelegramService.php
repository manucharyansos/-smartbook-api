<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function enabled(): bool
    {
        return (bool) config('services.telegram.enabled', false)
            && trim((string) config('services.telegram.bot_token')) !== '';
    }

    /**
     * @param array<int, string|int|null> $chatIds
     */
    public function sendToMany(array $chatIds, string $message): void
    {
        if (!$this->enabled()) {
            return;
        }

        $chatIds = collect($chatIds)
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($chatIds->isEmpty()) {
            Log::info('Telegram booking notification skipped: no chat id configured.');
            return;
        }

        foreach ($chatIds as $chatId) {
            $this->send((string) $chatId, $message);
        }
    }

    public function send(string $chatId, string $message): void
    {
        if (!$this->enabled() || trim($chatId) === '') {
            return;
        }

        $token = (string) config('services.telegram.bot_token');

        try {
            $response = Http::timeout(8)
                ->retry(2, 250)
                ->asForm()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'disable_web_page_preview' => true,
                ]);

            if (!$response->successful()) {
                Log::warning('Telegram booking notification failed', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram booking notification exception', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    public function bookingChatIdsForBusiness(Business $business): array
    {
        $ids = [];

        $configured = config('services.telegram.booking_chat_ids', []);
        if (is_string($configured)) {
            $ids = array_merge($ids, array_map('trim', explode(',', $configured)));
        } elseif (is_array($configured)) {
            $ids = array_merge($ids, $configured);
        }

        $single = trim((string) config('services.telegram.booking_chat_id', ''));
        if ($single !== '') {
            $ids[] = $single;
        }

        try {
            $ownersAndManagers = User::query()
                ->where('business_id', $business->id)
                ->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER])
                ->where('is_active', true)
                ->get();

            foreach ($ownersAndManagers as $user) {
                $ids = array_merge($ids, $this->staffBookingChatIds($user));
            }
        } catch (\Throwable $e) {
            // Do not let an optional column/database state break public booking.
        }

        return $this->normalizeChatIds($ids);
    }

    /**
     * @return array<int, string>
     */
    public function staffBookingChatIds(User $staff): array
    {
        return $this->normalizeChatIds([
            $staff->getAttribute('telegram_chat_id'),
        ]);
    }

    /**
     * @param array<int, string|int|null> $ids
     * @return array<int, string>
     */
    private function normalizeChatIds(array $ids): array
    {
        return collect($ids)
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function bookingConfirmedMessage(Booking $booking): string
    {
        $booking->loadMissing(['business', 'service', 'staff', 'client', 'items.service']);
        $business = $booking->business;
        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $related = $this->relatedBookings($booking);

        $serviceLabel = $this->servicesLabel($booking, $related);
        $staffLabel = $related->map(fn (Booking $item) => $item->staff?->name)->filter()->unique()->implode(', ') ?: ($booking->staff?->name ?? '—');
        $timeLabel = $this->timesLabel($related, $tz);
        $price = $this->priceLabel($related, $booking);

        $lines = [
            '✅ Նոր հաստատված ամրագրում',
            'Բիզնես՝ ' . ($business?->name ?? '—'),
            'Կոդ՝ ' . ($booking->booking_code ?: '—'),
            'Հաճախորդ՝ ' . ($booking->client_name ?: '—'),
            'Հեռախոս՝ ' . ($booking->client_phone ?: '—'),
            'Ծառայություն՝ ' . $serviceLabel,
            'Աշխատակից՝ ' . $staffLabel,
            'Ժամ՝ ' . $timeLabel,
            'Գին՝ ' . $price,
        ];

        if ($booking->notes) {
            $lines[] = 'Նշում՝ ' . mb_substr((string) $booking->notes, 0, 500);
        }

        return implode("\n", $lines);
    }

    public function bookingCancelledMessage(Booking $booking): string
    {
        $booking->loadMissing(['business', 'service', 'staff', 'items.service']);
        $business = $booking->business;
        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $related = $this->relatedBookings($booking);

        return implode("\n", [
            '❌ Ամրագրումը չեղարկվել է',
            'Բիզնես՝ ' . ($business?->name ?? '—'),
            'Կոդ՝ ' . ($booking->booking_code ?: '—'),
            'Հաճախորդ՝ ' . ($booking->client_name ?: '—'),
            'Հեռախոս՝ ' . ($booking->client_phone ?: '—'),
            'Ծառայություն՝ ' . $this->servicesLabel($booking, $related),
            'Ժամ՝ ' . $this->timesLabel($related, $tz),
        ]);
    }


    public function staffBookingConfirmedMessage(Booking $booking, User $staff): string
    {
        $booking->loadMissing(['business', 'client']);
        $business = $booking->business;
        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $related = $this->relatedBookingsForStaff($booking, $staff);

        $lines = [
            '✅ Քեզ նշանակված նոր ամրագրում',
            'Բիզնես՝ ' . ($business?->name ?? '—'),
            'Կոդ՝ ' . ($booking->booking_code ?: '—'),
            'Հաճախորդ՝ ' . ($booking->client_name ?: '—'),
            'Հեռախոս՝ ' . ($booking->client_phone ?: '—'),
            'Ծառայություն՝ ' . $this->servicesLabel($booking, $related),
            'Ժամ՝ ' . $this->timesLabel($related, $tz),
            'Գին՝ ' . $this->priceLabel($related, $booking),
        ];

        if ($booking->notes) {
            $lines[] = 'Նշում՝ ' . mb_substr((string) $booking->notes, 0, 500);
        }

        return implode("\n", $lines);
    }

    public function staffBookingCancelledMessage(Booking $booking, User $staff): string
    {
        $booking->loadMissing(['business']);
        $business = $booking->business;
        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $related = $this->relatedBookingsForStaff($booking, $staff);

        return implode("\n", [
            '❌ Քեզ նշանակված ամրագրումը չեղարկվել է',
            'Բիզնես՝ ' . ($business?->name ?? '—'),
            'Կոդ՝ ' . ($booking->booking_code ?: '—'),
            'Հաճախորդ՝ ' . ($booking->client_name ?: '—'),
            'Հեռախոս՝ ' . ($booking->client_phone ?: '—'),
            'Ծառայություն՝ ' . $this->servicesLabel($booking, $related),
            'Ժամ՝ ' . $this->timesLabel($related, $tz),
        ]);
    }

    private function relatedBookingsForStaff(Booking $booking, User $staff)
    {
        return $this->relatedBookings($booking)
            ->filter(fn (Booking $item) => (int) $item->staff_id === (int) $staff->id)
            ->values();
    }

    private function relatedBookings(Booking $booking)
    {
        return Booking::query()
            ->with(['service', 'staff', 'items.service'])
            ->where('client_id', $booking->client_id)
            ->when($booking->group_id, fn ($q) => $q->where('group_id', $booking->group_id), fn ($q) => $q->where('id', $booking->id))
            ->orderBy('starts_at')
            ->get();
    }

    private function servicesLabel(Booking $booking, $related): string
    {
        $names = collect();

        foreach ($related as $item) {
            $itemNames = $item->items
                ->map(fn ($bookingItem) => $bookingItem->service?->name)
                ->filter();

            if ($itemNames->isNotEmpty()) {
                $names = $names->merge($itemNames);
            } elseif ($item->service?->name) {
                $names->push($item->service->name);
            }
        }

        if ($names->isEmpty()) {
            $names->push($booking->service?->name ?? 'Ծառայություն');
        }

        return $names->filter()->values()->implode(', ');
    }

    private function timesLabel($related, string $timezone): string
    {
        return $related
            ->map(fn (Booking $item) => $item->starts_at ? $item->starts_at->copy()->timezone($timezone)->format('d.m.Y H:i') : null)
            ->filter()
            ->values()
            ->implode(', ') ?: '—';
    }

    private function priceLabel($related, Booking $booking): string
    {
        if ($related->contains(fn (Booking $item) => $item->final_price === null)) {
            return '—';
        }

        $total = $related->sum(fn (Booking $item) => (int) ($item->final_price ?? 0));
        $currency = $booking->currency ?: ($related->first()?->currency ?: 'AMD');

        return number_format((int) $total, 0, '.', ' ') . ' ' . $currency;
    }

}
