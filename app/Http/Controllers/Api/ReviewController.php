<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business side: read reviews, answer publicly, report abuse. A business cannot delete or
 * hide a review; Vizit's super admin decides on reported ones.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $request->validate(['staff_id' => ['nullable', 'integer'], 'unanswered' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = Review::query()->where('business_id', $business->id)
            ->with(['staff:id,name', 'service:id,name', 'booking:id,starts_at'])
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', (int) $request->integer('staff_id')))
            ->when($request->boolean('unanswered'), fn ($q) => $q->whereNull('reply'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Review $review) => $this->serialize($review))->values(),
            'meta' => [
                'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'summary' => $this->reviews->summary($business),
                'min_public_count' => $this->reviews->minPublicCount(),
            ],
        ]);
    }

    public function reply(Request $request, Review $review): JsonResponse
    {
        $business = $this->business($request);
        abort_unless((int) $review->business_id === (int) $business->id, 404);
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:1000']]);
        $reply = trim((string) ($data['reply'] ?? ''));
        $review->update(['reply' => $reply !== '' ? $reply : null, 'replied_at' => $reply !== '' ? now() : null]);

        return response()->json(['data' => $this->serialize($review->fresh(['staff:id,name', 'service:id,name', 'booking:id,starts_at']))]);
    }

    public function report(Request $request, Review $review): JsonResponse
    {
        $business = $this->business($request);
        abort_unless((int) $review->business_id === (int) $business->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $review->update(['reported_at' => now(), 'report_reason' => trim($data['reason'])]);

        return response()->json(['data' => $this->serialize($review->fresh(['staff:id,name', 'service:id,name', 'booking:id,starts_at']))]);
    }

    private function serialize(Review $review): array
    {
        return [
            ...$this->reviews->serializePublic($review),
            'visit_at' => $review->booking?->starts_at?->toIso8601String(),
            'reported_at' => $review->reported_at?->toIso8601String(),
            'report_reason' => $review->report_reason,
            'hidden' => $review->hidden_at !== null,
        ];
    }

    private function business(Request $request)
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true), 403);

        return $user->business ?? abort(404);
    }
}
