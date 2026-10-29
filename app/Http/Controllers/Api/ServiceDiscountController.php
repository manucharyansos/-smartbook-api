<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Services\ServiceDiscountService;
use Illuminate\Http\Request;

class ServiceDiscountController extends Controller
{
    public function __construct(private ServiceDiscountService $discounts)
    {
    }

    public function show(Request $request)
    {
        return response()->json(['data' => $this->discounts->tiers($this->business($request))]);
    }

    public function update(Request $request)
    {
        $business = $this->business($request);
        $max = ServiceDiscountService::MAX_TIERS;
        $percent = ServiceDiscountService::MAX_PERCENT;
        $data = $request->validate([
            'visit_tiers' => ['present', 'array', "max:{$max}"],
            'visit_tiers.*.min_visits' => ['required', 'integer', 'min:1', 'max:1000', 'distinct'],
            'visit_tiers.*.percent' => ['required', 'integer', 'min:1', "max:{$percent}"],
            'bundle_tiers' => ['present', 'array', "max:{$max}"],
            'bundle_tiers.*.min_services' => ['required', 'integer', 'min:2', 'max:20', 'distinct'],
            'bundle_tiers.*.percent' => ['required', 'integer', 'min:1', "max:{$percent}"],
        ]);
        $business->update(['service_discounts' => [
            'visit_tiers' => collect($data['visit_tiers'])->map(fn ($t) => ['min_visits' => (int) $t['min_visits'], 'percent' => (int) $t['percent']])->sortBy('min_visits')->values()->all(),
            'bundle_tiers' => collect($data['bundle_tiers'])->map(fn ($t) => ['min_services' => (int) $t['min_services'], 'percent' => (int) $t['percent']])->sortBy('min_services')->values()->all(),
        ]]);

        return response()->json(['data' => $this->discounts->tiers($business->fresh())]);
    }

    /** A signed-in client's visit count and next discount at one business. */
    public function progress(Request $request, string $slug)
    {
        $account = $request->user();
        abort_unless($account instanceof ClientAccount, 401);
        $business = Business::query()->where('slug', $slug)->firstOrFail();
        abort_if($business->isRental(), 404);
        $email = $account->hasVerifiedEmail() ? mb_strtolower(trim((string) $account->email)) : null;
        $clients = $email
            ? Client::query()->where('business_id', $business->id)->where('client_account_id', $account->id)->whereRaw('LOWER(email) = ?', [$email])->get()
            : collect();

        return response()->json(['data' => $this->discounts->progress($business, $clients)]);
    }

    private function business(Request $request): Business
    {
        abort_unless(in_array($request->user()?->role, ['owner', 'manager'], true), 403);
        $business = $request->user()->business;
        abort_unless($business && ! $business->isRental(), 404);

        return $business;
    }
}
