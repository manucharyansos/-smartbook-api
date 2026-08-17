<?php
// app/Http/Controllers/Api/BillingInvoiceController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingLifecycleService;
use App\Services\Billing\BusinessPricingResolver;
use Illuminate\Http\Request;

class BillingInvoiceController extends Controller
{
    public function __construct(
        private BillingLifecycleService $lifecycle,
        private BusinessPricingResolver $pricingResolver,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $items = Invoice::query()
            ->where('business_id', $user->business_id)
            ->with(['plan:id,name,code,price,monthly_price,yearly_price,currency,seats,staff_limit,duration_days'])
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function requestUpgrade(Request $request)
    {
        $user = $request->user();

        if ($user->role !== User::ROLE_OWNER) {
            abort(403);
        }

        $data = $request->validate([
            'plan_code' => ['required','string','exists:plans,code'],
            'payment_method' => ['nullable','string','in:bank_transfer,idram,card,cash'],
            'billing_cycle' => ['nullable','string','in:monthly,yearly'],
            'note' => ['nullable','string','max:255'],
        ]);

        $plan = Plan::query()
            ->where('code', $data['plan_code'])
            ->where('is_active', true)
            ->firstOrFail();

        $billingCycle = (string) ($data['billing_cycle'] ?? 'monthly');
        $periodDays = $billingCycle === 'yearly' ? 365 : (int) ($plan->duration_days ?: 30);

        $business = $user->business()->with(['subscription.plan'])->firstOrFail();
        $pricing = $this->pricingResolver->resolve($business, $plan, $billingCycle);

        $monthlyPrice = (int) $pricing['base_monthly_price'];
        $invoiceAmount = (int) $pricing['effective_amount'];
        $effectiveMonthly = (int) $pricing['effective_monthly_price'];
        $effectiveYearly = (int) $pricing['effective_yearly_price'];
        $override = $pricing['override'];

        if (!$plan->is_visible && !$override) {
            abort(404);
        }

        if ($plan->usesCustomPricing() && (!$override || $invoiceAmount <= 0)) {
            return response()->json([
                'message' => 'Այս պլանի համար անհրաժեշտ է գործող անհատական առաջարկ։',
                'code' => 'custom_offer_required',
            ], 422);
        }

        $staffLimit = $plan->staffLimit();
        $servicesLimit = $plan->getFeaturesList()['services_limit'] ?? null;
        $locationsLimit = max(1, (int) ($plan->locations ?? 1));
        $activeStaff = $business->activeSeatCount();
        $activeServices = $business->activeServiceCount();
        $locationsCount = $business->locationCount();

        $limitErrors = [];
        if ($staffLimit > 0 && $activeStaff > $staffLimit) {
            $limitErrors[] = 'staff';
        }
        if ($servicesLimit !== null && $activeServices > (int) $servicesLimit) {
            $limitErrors[] = 'services';
        }
        if ($locationsCount > $locationsLimit) {
            $limitErrors[] = 'locations';
        }

        if ($limitErrors !== []) {
            return response()->json([
                'message' => 'Ընտրված պլանի սահմանաչափերը փոքր են բիզնեսի ընթացիկ օգտագործումից։',
                'code' => 'plan_limits_exceeded',
                'data' => [
                    'exceeded' => $limitErrors,
                    'selected_plan' => [
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'staff_limit' => $staffLimit,
                        'services_limit' => $servicesLimit,
                        'locations_limit' => $locationsLimit,
                    ],
                    'usage' => [
                        'active_staff' => $activeStaff,
                        'active_services' => $activeServices,
                        'locations' => $locationsCount,
                    ],
                ],
            ], 409);
        }

        if ($invoiceAmount === 0) {
            $invoice = Invoice::create([
                'business_id' => $user->business_id,
                'plan_id' => $plan->id,
                'amount' => 0,
                'currency' => $plan->currency ?? 'AMD',
                'billing_cycle' => $billingCycle,
                'status' => 'pending',
                'payment_method' => 'free',
                'note' => 'Custom pricing / sales assisted activation.',
                'meta' => [
                    'billing_cycle' => $billingCycle,
                    'period_days' => $periodDays,
                    'base_monthly_amount' => $monthlyPrice,
                    'effective_monthly_amount' => $effectiveMonthly,
                    'effective_yearly_amount' => $effectiveYearly,
                    'yearly_months_charged' => $billingCycle === 'yearly' ? 10 : 1,
                    'yearly_months_free' => $billingCycle === 'yearly' ? 2 : 0,
                    'discount_amount' => (int) ($pricing['discount_amount'] ?? 0),
                    'pricing_override_id' => $override?->id,
                ],
            ]);

            $approved = $this->lifecycle->approveInvoice($invoice, [
                'provider' => 'internal',
                'note' => 'Free or custom plan activated instantly.',
            ]);

            return response()->json([
                'ok' => true,
                'mode' => 'instant',
                'data' => [
                    'plan' => ['code'=>$plan->code,'name'=>$plan->name,'price'=>$monthlyPrice,'currency'=>$plan->currency],
                    'subscription_status' => $approved->business?->subscription?->status ?? Subscription::STATUS_ACTIVE,
                    'invoice_id' => $approved->id,
                ]
            ]);
        }

        $existing = Invoice::query()
            ->where('business_id', $user->business_id)
            ->where('plan_id', $plan->id)
            ->where('status', 'pending')
            ->where('billing_cycle', $billingCycle)
            ->latest('id')
            ->first();

        if ($existing) {
            return response()->json([
                'ok' => true,
                'mode' => 'invoice',
                'data' => $existing->load('plan:id,name,code,price,monthly_price,yearly_price,currency,seats,staff_limit'),
                'provider' => [
                    'default' => config('billing.providers.default', 'idbank_mock'),
                    'mode' => config('billing.providers.mode', 'mock'),
                    'checkout_required' => true,
                ],
            ]);
        }

        $invoice = Invoice::create([
            'business_id' => $user->business_id,
            'plan_id' => $plan->id,
            'amount' => $invoiceAmount,
            'currency' => $plan->currency ?? 'AMD',
            'billing_cycle' => $billingCycle,
            'status' => 'pending',
            'payment_method' => $data['payment_method'] ?? null,
            'note' => $data['note'] ?? ($billingCycle === 'yearly' ? 'Տարեկան բաժանորդագրություն · 2 ամիս անվճար' : null),
            'meta' => [
                'billing_cycle' => $billingCycle,
                'period_days' => $periodDays,
                'base_monthly_amount' => $monthlyPrice,
                'effective_monthly_amount' => $effectiveMonthly,
                'effective_yearly_amount' => $effectiveYearly,
                'yearly_months_charged' => $billingCycle === 'yearly' ? 10 : 1,
                'yearly_months_free' => $billingCycle === 'yearly' ? 2 : 0,
                'full_year_amount' => $billingCycle === 'yearly' ? ($monthlyPrice * 12) : null,
                'discount_amount' => (int) ($pricing['discount_amount'] ?? 0),
                'pricing_override_id' => $override?->id,
                'override_discount_type' => $pricing['discount_type'],
                'override_discount_value' => $pricing['discount_value'],
            ],
        ]);

        $bank = config('billing.bank');
        $idram = config('billing.idram');

        return response()->json([
            'ok' => true,
            'mode' => 'invoice',
            'data' => $invoice->load('plan:id,name,code,price,monthly_price,yearly_price,currency,seats,staff_limit'),
            'payment' => [
                'bank_transfer' => [
                    'company' => $bank['company_name'],
                    'bank_name' => $bank['bank_name'],
                    'account_number' => $bank['account_number'],
                    'recipient' => $bank['recipient_name'],
                    'payment_note' => str_replace(
                        [':id', ':business'],
                        [$invoice->id, $invoice->business_id],
                        $bank['note_template']
                    ),
                ],
                'idram' => [
                    'wallet' => $idram['wallet_id'],
                    'payment_note' => str_replace(':id', $invoice->id, $idram['note_template']),
                ],
                'message' => 'Այժմ կարող եք ստեղծել payment session և հետո webhook/mock success-ով invoice-ը կակտիվանա ավտոմատ։',
                'billing_cycle' => $billingCycle,
                'amount_summary' => [
                    'base_monthly_amount' => $monthlyPrice,
                    'effective_monthly_amount' => $effectiveMonthly,
                    'effective_yearly_amount' => $effectiveYearly,
                    'invoice_amount' => $invoiceAmount,
                    'discount_amount' => (int) ($pricing['discount_amount'] ?? 0),
                ],
            ],
            'provider' => [
                'default' => config('billing.providers.default', 'idbank_mock'),
                'mode' => config('billing.providers.mode', 'mock'),
                'checkout_required' => true,
                'next_step' => 'POST /api/billing/checkout-session',
            ],
        ], 201);
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        if ($invoice->business_id !== $user->business_id) abort(404);

        if ($invoice->status !== 'pending') {
            return response()->json(['ok' => true]);
        }

        $invoice->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
