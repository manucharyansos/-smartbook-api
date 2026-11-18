<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Review;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reviews after a finished visit: one review per booking, written by the client within
 * the review window. A visit counts as finished when the business marked it done, or when
 * a confirmed booking has ended. Cancelled and no-show visits can never be reviewed.
 */
class ReviewService
{
    public function windowDays(): int
    {
        return max(1, (int) config('reviews.window_days', 30));
    }

    /** @return array{eligible: bool, reason: ?string, deadline: ?string} */
    public function eligibility(Booking $booking): array
    {
        $deadline = $booking->ends_at ? Carbon::parse($booking->ends_at)->addDays($this->windowDays()) : null;
        $reason = match (true) {
            $booking->relationLoaded('review') ? (bool) $booking->review : $booking->review()->exists() => 'already_reviewed',
            in_array($booking->status, ['cancelled', 'no_show'], true) => 'not_visited',
            !$booking->ends_at || !$this->visitFinished($booking) => 'not_finished',
            $deadline && now()->gt($deadline) => 'expired',
            default => null,
        };

        return ['eligible' => $reason === null, 'reason' => $reason, 'deadline' => $deadline?->toIso8601String()];
    }

    public function visitFinished(Booking $booking): bool
    {
        if ($booking->status === 'done') return true;

        return $booking->status === 'confirmed' && $booking->ends_at && now()->gte($booking->ends_at);
    }

    /** State shown next to a booking in the guest page, the client cabinet and the app. */
    public function stateFor(Booking $booking): array
    {
        $booking->loadMissing('review');

        return [
            ...$this->eligibility($booking),
            'review' => $booking->review ? $this->serializeOwn($booking->review) : null,
        ];
    }

    public function create(Booking $booking, array $data): Review
    {
        return DB::transaction(function () use ($booking, $data) {
            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $state = $this->eligibility($locked);
            if (!$state['eligible']) {
                throw ValidationException::withMessages(['rating' => $this->reasonMessage($state['reason'])]);
            }
            $comment = trim((string) ($data['comment'] ?? ''));

            return Review::create([
                'business_id' => $locked->business_id,
                'booking_id' => $locked->id,
                'client_id' => $locked->client_id,
                'staff_id' => $locked->staff_id,
                'service_id' => $locked->service_id,
                'rating' => (int) $data['rating'],
                'comment' => $comment !== '' ? $comment : null,
                'author_name' => $this->authorName((string) $locked->client_name),
            ]);
        });
    }

    /** "Ani Martirosyan" → "Ani M." — the full name never appears publicly. */
    public function authorName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$parts) return 'Vizit';
        $first = Str::limit($parts[0], 40, '');
        $last = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)).'.' : '';

        return trim($first.' '.$last);
    }

    /** @return array{average: ?float, count: int, shown: bool} */
    public function summary(Business $business): array
    {
        $row = Review::query()->visible()->where('business_id', $business->id)
            ->selectRaw('count(*) as total, avg(rating) as average')->first();
        $count = (int) ($row->total ?? 0);
        $shown = $count >= $this->minPublicCount();

        return ['average' => $shown ? round((float) $row->average, 1) : null, 'count' => $count, 'shown' => $shown];
    }

    /** @return array<int, array{average: ?float, count: int}> staff id => rating */
    public function staffSummaries(int $businessId): array
    {
        return Review::query()->visible()->where('business_id', $businessId)->whereNotNull('staff_id')
            ->groupBy('staff_id')->selectRaw('staff_id, count(*) as total, avg(rating) as average')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->staff_id => [
                'count' => (int) $row->total,
                'average' => (int) $row->total >= $this->minPublicCount() ? round((float) $row->average, 1) : null,
            ]])->all();
    }

    /** @return array<int, array{average: ?float, count: int}> business id => rating, for directory cards */
    public function businessSummaries(array $businessIds): array
    {
        if (!$businessIds) return [];

        return Review::query()->visible()->whereIn('business_id', $businessIds)
            ->groupBy('business_id')->selectRaw('business_id, count(*) as total, avg(rating) as average')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->business_id => [
                'count' => (int) $row->total,
                'average' => (int) $row->total >= $this->minPublicCount() ? round((float) $row->average, 1) : null,
            ]])->all();
    }

    public function minPublicCount(): int
    {
        return max(1, (int) config('reviews.min_public_count', 3));
    }

    public function serializePublic(Review $review): array
    {
        return [
            'id' => $review->id,
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'author_name' => $review->author_name,
            'service' => $review->service ? ['id' => (int) $review->service->id, 'name' => $review->service->name] : null,
            'staff' => $review->staff ? ['id' => (int) $review->staff->id, 'name' => $review->staff->name] : null,
            'reply' => $review->reply,
            'replied_at' => $review->replied_at?->toIso8601String(),
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    public function serializeOwn(Review $review): array
    {
        return [
            'id' => $review->id,
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'reply' => $review->reply,
            'hidden' => $review->hidden_at !== null,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    /** Creates a fresh token for the "rate your visit" link; only its hash is stored. */
    public function issueReviewToken(Booking $booking): string
    {
        $token = Str::random(48);
        $booking->forceFill(['review_token_hash' => Hash::make($token)])->save();

        return $token;
    }

    public function reviewTokenValid(Booking $booking, string $token): bool
    {
        return $token !== '' && $booking->review_token_hash && Hash::check($token, (string) $booking->review_token_hash);
    }

    public function reasonMessage(?string $reason): string
    {
        return match ($reason) {
            'already_reviewed' => 'Այս այցի համար կարծիքն արդեն թողնված է։',
            'not_visited' => 'Չեղարկված կամ չկայացած այցի համար կարծիք թողնել հնարավոր չէ։',
            'not_finished' => 'Կարծիք կարող եք թողնել այցից հետո։',
            'expired' => 'Կարծիք թողնելու ժամկետն անցել է։',
            default => 'Կարծիք թողնել հնարավոր չէ։',
        };
    }
}
