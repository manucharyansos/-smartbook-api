<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RentalSettingsController extends Controller
{
    public function schedule(Request $request)
    {
        $this->business($request);

        return app(ScheduleController::class)->show($request);
    }

    public function saveSchedule(Request $request)
    {
        $this->business($request);

        return app(ScheduleController::class)->update($request);
    }

    private function business(Request $request): Business
    {
        abort_unless(in_array($request->user()?->role, ['owner', 'manager'], true), 403);
        $business = $request->user()->business;
        abort_unless($business?->isRental(), 404);

        return $business;
    }

    public function show(Request $request)
    {
        $business = $this->business($request);

        return response()->json(['data' => [
            'hourly_price' => $business->rental_hourly_price,
            'min_minutes' => $business->rental_min_minutes,
            'max_minutes' => $business->rental_max_minutes,
            'buffer_minutes' => $business->rental_buffer_minutes,
            'slot_step_minutes' => (int) $business->slot_step_minutes,
            'gallery' => $business->gallery()->get(),
        ]]);
    }

    public function update(Request $request)
    {
        $business = $this->business($request);
        $data = $request->validate([
            'hourly_price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'min_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'max_minutes' => ['required', 'integer', 'gte:min_minutes', 'max:1440'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'slot_step_minutes' => ['required', 'integer', 'in:5,10,15,20,30,60'],
        ]);
        if ($data['min_minutes'] % $data['slot_step_minutes'] || $data['max_minutes'] % $data['slot_step_minutes']) {
            throw ValidationException::withMessages(['min_minutes' => 'Տևողությունները պետք է բաժանվեն ժամային քայլի վրա։']);
        }
        DB::transaction(function () use ($business, $data) {
            $business = Business::query()->lockForUpdate()->findOrFail($business->id);
            $business->update([
                'rental_hourly_price' => $data['hourly_price'], 'rental_min_minutes' => $data['min_minutes'],
                'rental_max_minutes' => $data['max_minutes'], 'rental_buffer_minutes' => $data['buffer_minutes'],
                'slot_step_minutes' => $data['slot_step_minutes'], 'show_services' => false, 'show_staff' => false,
            ]);
        });
        $request->user()->unsetRelation('business');

        return $this->show($request);
    }

    public function upload(Request $request)
    {
        $business = $this->business($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm', 'max:51200'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $request->file('file');
        $mime = $file->getMimeType();
        if (str_starts_with($mime, 'image/') && $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Նկարի չափը պետք է լինի առավելագույնը 10 ՄԲ։']);
        }
        $path = $file->store('businesses/'.$business->id.'/gallery', 'public');
        try {
            $media = DB::transaction(function () use ($business, $path, $mime, $data) {
                Business::query()->lockForUpdate()->findOrFail($business->id);
                if ($business->gallery()->count() >= 20) {
                    throw ValidationException::withMessages(['file' => 'Պատկերասրահում թույլատրվում է մինչև 20 ֆայլ։']);
                }

                return $business->gallery()->create([
                    'path' => $path, 'mime_type' => $mime, 'kind' => str_starts_with($mime, 'video/') ? 'video' : 'image',
                    'caption' => $data['caption'] ?? null, 'sort_order' => (int) $business->gallery()->max('sort_order') + 1,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $media], 201);
    }

    public function updateMedia(Request $request, BusinessMedia $media)
    {
        $business = $this->business($request);
        abort_unless((int) $media->business_id === (int) $business->id, 404);
        $data = $request->validate(['caption' => ['nullable', 'string', 'max:255'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000']]);
        $media->update($data);

        return response()->json(['data' => $media]);
    }

    public function destroyMedia(Request $request, BusinessMedia $media)
    {
        $business = $this->business($request);
        abort_unless((int) $media->business_id === (int) $business->id, 404);
        $path = $media->getRawOriginal('path');
        $media->delete();
        Storage::disk('public')->delete($path);

        return response()->json(['ok' => true]);
    }
}
