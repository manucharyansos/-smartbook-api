<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        [$owner, $audience] = $this->ownerAndAudience($request);

        $data = $request->validate([
            'expo_push_token' => ['required', 'string', 'max:255', 'regex:/^ExponentPushToken\[[^\]]+\]$|^ExpoPushToken\[[^\]]+\]$/'],
            'platform' => ['required', 'in:ios,android'],
            'device_id' => ['nullable', 'string', 'max:190'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'locale' => ['nullable', 'in:hy,ru,en'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        $device = MobileDevice::query()->updateOrCreate(
            ['expo_push_token' => $data['expo_push_token']],
            [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'audience' => $audience,
                'platform' => $data['platform'],
                'device_id' => $data['device_id'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'locale' => $data['locale'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'last_seen_at' => now(),
                'disabled_at' => null,
            ],
        );

        return response()->json(['data' => $device], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyCurrent(Request $request): JsonResponse
    {
        [$owner] = $this->ownerAndAudience($request);
        $data = $request->validate([
            'expo_push_token' => ['required', 'string', 'max:255'],
        ]);

        MobileDevice::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('expo_push_token', $data['expo_push_token'])
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array{0: Model, 1: string} */
    private function ownerAndAudience(Request $request): array
    {
        $owner = $request->user();

        if ($owner instanceof ClientAccount) {
            return [$owner, 'client'];
        }

        if ($owner instanceof User && $owner->business_id !== null) {
            return [$owner, 'business'];
        }

        abort(403, 'This account cannot register a mobile device.');
    }
}
