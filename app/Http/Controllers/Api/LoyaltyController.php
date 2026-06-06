<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoyaltyPointLedger;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoyaltyController extends Controller
{
    public function program(Request $request, LoyaltyService $svc)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $program = $svc->getOrCreateProgram((int) $actor->business_id);
        return response()->json(['data' => $program]);
    }

    public function updateProgram(Request $request, LoyaltyService $svc)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if (!in_array($actor->role, ['owner', 'manager'], true)) abort(403);

        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'currency_unit' => ['required', 'integer', 'min:1', 'max:1000000'],
            'points_per_currency_unit' => ['required', 'integer', 'min:0', 'max:1000'],
            'redeem_points_step' => ['required', 'integer', 'min:1', 'max:1000000'],
            'redeem_currency_amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'max_redeem_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'allow_gift_card_with_points' => ['required', 'boolean'],
            'points_expire_after_days' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'min_booking_amount' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $program = $svc->getOrCreateProgram((int) $actor->business_id);
        $program->update([
            'is_enabled' => (bool) $data['is_enabled'],
            'currency_unit' => (int) $data['currency_unit'],
            'points_per_currency_unit' => (int) $data['points_per_currency_unit'],
            'redeem_points_step' => (int) $data['redeem_points_step'],
            'redeem_currency_amount' => (int) $data['redeem_currency_amount'],
            'max_redeem_percent' => (int) $data['max_redeem_percent'],
            'allow_gift_card_with_points' => (bool) $data['allow_gift_card_with_points'],
            'points_expire_after_days' => (int) ($data['points_expire_after_days'] ?? 0),
            'min_booking_amount' => (int) ($data['min_booking_amount'] ?? 0),
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => $program->fresh()]);
    }

    public function clients(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $q = Client::query()->where('business_id', $actor->business_id);
        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $q->where(function ($qq) use ($term) {
                $qq->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        $clients = $q->orderBy('name')->limit(500)->get();

        $balances = DB::table('loyalty_point_ledgers')
            ->select('client_id', DB::raw('COALESCE(SUM(delta_points),0) as points'))
            ->where('business_id', $actor->business_id)
            ->groupBy('client_id')
            ->pluck('points', 'client_id');

        $lifetimeEarned = DB::table('loyalty_point_ledgers')
            ->select('client_id', DB::raw('COALESCE(SUM(CASE WHEN delta_points > 0 THEN delta_points ELSE 0 END),0) as points'))
            ->where('business_id', $actor->business_id)
            ->groupBy('client_id')
            ->pluck('points', 'client_id');

        $data = $clients->map(function (Client $c) use ($balances, $lifetimeEarned) {
            return [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'points' => (int) ($balances[$c->id] ?? 0),
                'lifetime_earned' => (int) ($lifetimeEarned[$c->id] ?? 0),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function clientLedger(Request $request, Client $client)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if ((int) $client->business_id !== (int) $actor->business_id) abort(404);

        $entries = LoyaltyPointLedger::query()
            ->where('business_id', $actor->business_id)
            ->where('client_id', $client->id)
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $entries]);
    }

    public function preview(Request $request, LoyaltyService $svc)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'gross_amount' => ['required', 'integer', 'min:0'],
            'requested_points' => ['required', 'integer', 'min:0'],
        ]);

        $client = Client::query()->findOrFail((int) $data['client_id']);
        if ((int) $client->business_id !== (int) $actor->business_id) abort(404);

        $program = $svc->getOrCreateProgram((int) $actor->business_id);
        $balance = $svc->getClientBalance((int) $actor->business_id, (int) $client->id);
        $preview = $svc->previewRedemption($program, $balance, (int) $data['gross_amount'], (int) $data['requested_points']);

        return response()->json([
            'data' => [
                'balance' => $balance,
                'applied_points' => (int) ($preview['applied_points'] ?? 0),
                'discount_amount' => (int) ($preview['discount_amount'] ?? 0),
            ],
        ]);
    }

    public function adjust(Request $request, Client $client, LoyaltyService $svc)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if ((int) $client->business_id !== (int) $actor->business_id) abort(404);
        if (!in_array($actor->role, ['owner', 'manager'], true)) abort(403);

        $data = $request->validate([
            'delta_points' => ['required', 'integer', 'min:-1000000', 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $ledger = $svc->adjust($actor, $client, (int) $data['delta_points'], $data['reason'] ?? null);

        return response()->json(['data' => $ledger], 201);
    }
}
