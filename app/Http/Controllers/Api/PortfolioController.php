<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMedia;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Work photos ("portfolio"): uploaded once, optionally tagged with one specialist and
 * one service, so the same photo shows on the business page, the specialist and the service.
 */
class PortfolioController extends Controller
{
    public const MAX_ITEMS = 60;

    public const MAX_IMAGE_KB = 10240;

    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        return response()->json([
            'data' => $business->portfolio()->with(['staff:id,name', 'service:id,name'])->get()->map(fn (BusinessMedia $item) => self::serialize($item))->values(),
            'meta' => ['limit' => self::MAX_ITEMS],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.self::MAX_IMAGE_KB],
            ...$this->tagRules($business),
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $path = $file->store('businesses/'.$business->id.'/portfolio', 'public');

        try {
            $media = DB::transaction(function () use ($business, $path, $mime, $data) {
                Business::query()->lockForUpdate()->findOrFail($business->id);
                if ($business->portfolio()->count() >= self::MAX_ITEMS) {
                    throw ValidationException::withMessages(['file' => 'Աշխատանքների բաժնում թույլատրվում է մինչև '.self::MAX_ITEMS.' լուսանկար։']);
                }

                return $business->portfolio()->create([
                    'path' => $path,
                    'mime_type' => $mime,
                    'kind' => 'image',
                    'collection' => BusinessMedia::COLLECTION_PORTFOLIO,
                    'staff_id' => $data['staff_id'] ?? null,
                    'service_id' => $data['service_id'] ?? null,
                    'caption' => $data['caption'] ?? null,
                    'sort_order' => 0,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => self::serialize($media->load(['staff:id,name', 'service:id,name']))], 201);
    }

    public function update(Request $request, BusinessMedia $media): JsonResponse
    {
        $business = $this->business($request);
        $this->assertOwned($media, $business);
        $data = $request->validate([
            ...$this->tagRules($business),
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        $media->update($data);

        return response()->json(['data' => self::serialize($media->fresh()->load(['staff:id,name', 'service:id,name']))]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::MAX_ITEMS],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $owned = $business->portfolio()->whereIn('id', $data['ids'])->pluck('id')->all();
        DB::transaction(function () use ($data, $owned) {
            foreach (array_values(array_intersect($data['ids'], $owned)) as $position => $id) {
                BusinessMedia::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return $this->index($request);
    }

    public function destroy(Request $request, BusinessMedia $media): JsonResponse
    {
        $business = $this->business($request);
        $this->assertOwned($media, $business);
        $path = $media->getRawOriginal('path');
        $media->delete();
        Storage::disk('public')->delete($path);

        return response()->json(['ok' => true]);
    }

    /** Shape shared by the admin and the public endpoints. */
    public static function serialize(BusinessMedia $item, bool $includeStaff = true): array
    {
        $staff = $includeStaff && $item->relationLoaded('staff') ? $item->staff : null;
        $service = $item->relationLoaded('service') ? $item->service : null;

        return [
            'id' => $item->id,
            'url' => $item->url,
            'caption' => $item->caption,
            'sort_order' => (int) $item->sort_order,
            'staff_id' => $staff ? (int) $staff->id : null,
            'service_id' => $service ? (int) $service->id : null,
            'staff' => $staff ? ['id' => (int) $staff->id, 'name' => $staff->name] : null,
            'service' => $service ? ['id' => (int) $service->id, 'name' => $service->name] : null,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    private function tagRules(Business $business): array
    {
        return [
            'caption' => ['nullable', 'string', 'max:255'],
            'staff_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('business_id', $business->id)],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('business_id', $business->id)],
        ];
    }

    private function business(Request $request): Business
    {
        $user = $request->user();
        abort_unless($user && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true), 403);
        $business = $user->business;
        abort_unless($business && !$business->isRental(), 404);

        return $business;
    }

    private function assertOwned(BusinessMedia $media, Business $business): void
    {
        abort_unless((int) $media->business_id === (int) $business->id && $media->collection === BusinessMedia::COLLECTION_PORTFOLIO, 404);
    }
}
