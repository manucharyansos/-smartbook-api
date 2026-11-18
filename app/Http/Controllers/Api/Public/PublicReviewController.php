<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PublicReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /** GET /public/bookings/{code}/review — what the client may do for this visit. */
    public function show(string $code, Request $request): JsonResponse
    {
        $booking = $this->authorizedBooking($code, $request);

        return response()->json(['data' => [
            ...$this->reviews->stateFor($booking),
            'booking' => [
                'id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'starts_at' => $booking->starts_at?->toIso8601String(),
                'business' => $booking->business ? ['name' => $booking->business->name, 'slug' => $booking->business->slug, 'logo_url' => $booking->business->logo_url] : null,
                'service' => $booking->service ? ['name' => $booking->service->name] : null,
                'staff' => $booking->staff ? ['name' => $booking->staff->name] : null,
            ],
        ]]);
    }

    /** POST /public/bookings/{code}/review {rating, comment?, booking_id?} */
    public function store(string $code, Request $request): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:'.config('reviews.comment_max', 1000)],
            'booking_id' => ['nullable', 'integer'],
        ]);
        $booking = $this->authorizedBooking($code, $request);
        $review = $this->reviews->create($booking, $data);

        return response()->json(['data' => $this->reviews->serializeOwn($review)], 201);
    }

    /** GET /public/businesses/{slug}/reviews?staff_id=&page= */
    public function index(string $slug, Request $request): JsonResponse
    {
        $request->validate(['staff_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $business = $this->publicBusiness($slug);
        $page = Review::query()->visible()->where('business_id', $business->id)
            ->with(['staff:id,name,is_active', 'service:id,name'])
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', (int) $request->integer('staff_id')))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($request->integer('per_page') ?: 10));

        return response()->json([
            'data' => collect($page->items())->map(function (Review $review) {
                if ($review->staff && !$review->staff->is_active) $review->setRelation('staff', null);

                return $this->reviews->serializePublic($review);
            })->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'summary' => $this->reviews->summary($business)],
        ]);
    }

    /**
     * The booking may be opened with the guest manage token (same rules as the booking page)
     * or with the token from the "rate your visit" email. booking_id picks one visit of a
     * multi-service booking; it must belong to the same client and booking group.
     */
    private function authorizedBooking(string $code, Request $request): Booking
    {
        $booking = Booking::query()->with(['business:id,name,slug,logo_url', 'service:id,name', 'staff:id,name'])
            ->where('booking_code', $code)->firstOrFail();
        $guestToken = (string) ($request->bearerToken() ?: $request->header('X-Guest-Token') ?: $request->input('token', ''));
        $reviewToken = (string) ($request->header('X-Review-Token') ?: $request->input('review_token', ''));

        $guestOk = $booking->phone_verified_at && $guestToken !== '' && $booking->guest_access_expires_at
            && now()->lte($booking->guest_access_expires_at) && Hash::check($guestToken, (string) $booking->guest_access_token_hash);
        abort_unless($guestOk || $this->reviews->reviewTokenValid($booking, $reviewToken), 401, 'Review access is required.');

        $bookingId = (int) $request->input('booking_id', $request->query('booking_id', 0));
        if ($bookingId && $bookingId !== (int) $booking->id) {
            abort_unless($booking->group_id, 404);
            $booking = Booking::query()->with(['business:id,name,slug,logo_url', 'service:id,name', 'staff:id,name'])
                ->whereKey($bookingId)->where('group_id', $booking->group_id)->where('client_id', $booking->client_id)
                ->where('business_id', $booking->business_id)->firstOrFail();
        }

        return $booking;
    }

    private function publicBusiness(string $slug): Business
    {
        // Same visibility rules as the public profile.
        return app(PublicBookingController::class)->publicBusinessForReviews($slug);
    }
}
