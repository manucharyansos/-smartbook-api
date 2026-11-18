<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Vizit super admin: reported reviews and the hide/show decision. */
class ReviewModerationController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:reported,hidden,all']]);
        $status = $request->input('status', 'reported');
        $page = Review::query()->with(['business:id,name,slug', 'staff:id,name', 'service:id,name'])
            ->when($status === 'reported', fn ($q) => $q->whereNotNull('reported_at')->whereNull('hidden_at'))
            ->when($status === 'hidden', fn ($q) => $q->whereNotNull('hidden_at'))
            ->orderByDesc('reported_at')->orderByDesc('id')
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Review $review) => $this->serialize($review))->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function update(Request $request, Review $review): JsonResponse
    {
        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $old = ['hidden_at' => $review->hidden_at?->toIso8601String()];
        $review->update($data['hidden']
            ? ['hidden_at' => now(), 'hidden_by_admin_id' => $request->user()?->id]
            // Showing again clears the report so it leaves the queue.
            : ['hidden_at' => null, 'hidden_by_admin_id' => null, 'reported_at' => null, 'report_reason' => null]);

        AdminLog::create([
            'admin_id' => $request->user()?->id,
            'action' => $data['hidden'] ? 'review.hide' : 'review.show',
            'model_type' => Review::class,
            'model_id' => $review->id,
            'old_values' => $old,
            'new_values' => ['hidden_at' => $review->hidden_at?->toIso8601String()],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['data' => $this->serialize($review->fresh(['business:id,name,slug', 'staff:id,name', 'service:id,name']))]);
    }

    private function serialize(Review $review): array
    {
        return [
            ...$this->reviews->serializePublic($review),
            'business' => $review->business ? ['id' => $review->business->id, 'name' => $review->business->name, 'slug' => $review->business->slug] : null,
            'reported_at' => $review->reported_at?->toIso8601String(),
            'report_reason' => $review->report_reason,
            'hidden' => $review->hidden_at !== null,
        ];
    }
}
