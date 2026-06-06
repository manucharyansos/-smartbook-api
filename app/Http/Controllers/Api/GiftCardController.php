<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\GiftCardLedger;
use App\Models\User;
use App\Services\GiftCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Closure;

class GiftCardController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $q = GiftCard::query();
        if ($actor->isSuperAdmin()) {
            if ($request->filled('business_id')) {
                $q->where('business_id', $request->integer('business_id'));
            }
        } else {
            $q->where('business_id', $actor->business_id);
        }

        if ($request->filled('status')) {
            $q->where('status', (string) $request->string('status'));
        }
        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $q->where(function ($qq) use ($term) {
                $qq->where('code', 'like', "%{$term}%")
                    ->orWhere('issued_to_name', 'like', "%{$term}%")
                    ->orWhere('issued_to_phone', 'like', "%{$term}%")
                    ->orWhere('purchased_by_name', 'like', "%{$term}%")
                    ->orWhere('purchased_by_phone', 'like', "%{$term}%");
            });
        }

        return response()->json(['data' => $q->orderByDesc('id')->limit(500)->get()]);
    }

    public function store(Request $request, GiftCardService $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if ($actor->role === User::ROLE_STAFF) abort(403);

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'amount' => ['required', 'integer', 'min:100', 'max:100000000'],
            'currency' => ['nullable', 'string', 'max:8'],
            'issued_to_name' => ['nullable', 'string', 'max:120'],
            'issued_to_phone' => ['nullable', 'string', 'max:40'],
            'purchased_by_name' => ['nullable', 'string', 'max:120'],
            'purchased_by_phone' => ['nullable', 'string', 'max:40'],
            'expires_at' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if ($value === null || $value === '') return;
                if (!$this->normalizeExpiryDate($value)) {
                    $fail('The expires at field must be a valid date.');
                }
            }],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $businessId = $actor->isSuperAdmin() ? (int) ($request->integer('business_id') ?: 0) : (int) $actor->business_id;
        if (!$businessId) {
            throw ValidationException::withMessages(['business_id' => 'business_id is required for super admin']);
        }

        if (!empty($data['expires_at'])) {
            $data['expires_at'] = Carbon::parse($data['expires_at'])->endOfDay();
        }

        $giftCard = $service->issue($actor, $businessId, $data);

        return response()->json(['data' => $giftCard], 201);
    }

    public function show(Request $request, GiftCard $giftCard)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if (!$actor->isSuperAdmin() && (int) $giftCard->business_id !== (int) $actor->business_id) abort(404);

        return response()->json(['data' => $giftCard]);
    }

    public function ledger(Request $request, GiftCard $giftCard)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if (!$actor->isSuperAdmin() && (int) $giftCard->business_id !== (int) $actor->business_id) abort(404);

        $entries = GiftCardLedger::query()
            ->where('gift_card_id', $giftCard->id)
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $entries]);
    }

    public function lookup(Request $request, GiftCardService $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $giftCard = $service->lookupActiveByCode((int) $actor->business_id, (string) $data['code']);

        return response()->json(['data' => $giftCard]);
    }

    public function update(Request $request, GiftCard $giftCard)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if ($actor->role === User::ROLE_STAFF) abort(403);
        if (!$actor->isSuperAdmin() && (int) $giftCard->business_id !== (int) $actor->business_id) abort(404);

        $data = $request->validate([
            'issued_to_name' => ['nullable', 'string', 'max:120'],
            'issued_to_phone' => ['nullable', 'string', 'max:40'],
            'purchased_by_name' => ['nullable', 'string', 'max:120'],
            'purchased_by_phone' => ['nullable', 'string', 'max:40'],
            'expires_at' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if ($value === null || $value === '') return;
                if (!$this->normalizeExpiryDate($value)) {
                    $fail('The expires at field must be a valid date.');
                }
            }],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:active,cancelled'],
        ]);

        if (array_key_exists('expires_at', $data)) {
            $giftCard->expires_at = $this->normalizeExpiryDate($data['expires_at'] ?? null);
        }
        foreach (['issued_to_name', 'issued_to_phone', 'purchased_by_name', 'purchased_by_phone', 'notes'] as $k) {
            if (array_key_exists($k, $data)) $giftCard->{$k} = $data[$k];
        }
        if (!empty($data['status'])) {
            if ($data['status'] === 'cancelled' && $giftCard->status !== 'redeemed') $giftCard->status = 'cancelled';
            if ($data['status'] === 'active' && $giftCard->status !== 'redeemed') $giftCard->status = 'active';
        }
        $giftCard->save();

        return response()->json(['data' => $giftCard]);
    }

    public function redeem(Request $request, GiftCard $giftCard, GiftCardService $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if ($actor->role === User::ROLE_STAFF) abort(403);
        if (!$actor->isSuperAdmin() && (int) $giftCard->business_id !== (int) $actor->business_id) abort(404);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $giftCard = $service->manualRedeem($actor, $giftCard, (int) $data['amount'], $data['reason'] ?? null);
        return response()->json(['data' => $giftCard]);
    }

    public function adjust(Request $request, GiftCard $giftCard, GiftCardService $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if ($actor->role === User::ROLE_STAFF) abort(403);
        if (!$actor->isSuperAdmin() && (int) $giftCard->business_id !== (int) $actor->business_id) abort(404);

        $data = $request->validate([
            'delta_amount' => ['required', 'integer', 'min:-100000000', 'max:100000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $giftCard = $service->adjust($actor, $giftCard, (int) $data['delta_amount'], $data['reason'] ?? null);
        return response()->json(['data' => $giftCard]);
    }
}
