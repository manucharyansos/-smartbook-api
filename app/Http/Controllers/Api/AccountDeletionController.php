<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Models\ClientAccount;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Http\Request;

class AccountDeletionController extends Controller
{
    public function store(Request $request)
    {
        $actor = $request->user();
        $audience = $actor instanceof ClientAccount ? 'client' : ($actor instanceof User && $actor->business_id ? 'business' : null);
        abort_unless($audience, 403, 'This account cannot request deletion.');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $deletion = AccountDeletionRequest::query()->firstOrCreate([
            'requester_type' => $actor->getMorphClass(),
            'requester_id' => $actor->getKey(),
            'status' => 'pending',
        ], [
            'audience' => $audience,
            'email' => $actor->email,
            'reason' => $data['reason'] ?? null,
            'requested_at' => now(),
        ]);

        MobileDevice::query()->where('owner_type', $actor->getMorphClass())->where('owner_id', $actor->getKey())->delete();
        $actor->tokens()->delete();

        return response()->json([
            'ok' => true,
            'data' => ['id' => $deletion->id, 'status' => $deletion->status, 'requested_at' => $deletion->requested_at],
            'message' => 'Account deletion request accepted. The account and associated personal data will be reviewed and deleted subject to legal retention requirements.',
        ], 202);
    }
}
