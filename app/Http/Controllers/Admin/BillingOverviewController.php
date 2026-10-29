<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Subscription;
use App\Services\Billing\BillingLifecycleService;
use App\Services\Billing\BillingPaymentProviders;
use App\Services\Billing\IdramGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vizit's own subscription income: invoices businesses pay to Vizit and the
 * payment attempts behind them. Customer booking revenue is reported elsewhere.
 */
class BillingOverviewController extends Controller
{
    public function summary(Request $request)
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        $paid = fn () => $this->invoiceQuery()->where('status', 'approved')->where('amount', '>', 0);
        $income = (int) $paid()->whereBetween('paid_at', [$from, $to])->sum('amount');
        $onlineIncome = (int) $paid()->whereBetween('paid_at', [$from, $to])
            ->whereHas('paymentTransactions', fn ($q) => $q->where('status', 'paid'))->sum('amount');

        $subscriptions = Subscription::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $providers = BillingPaymentProviders::status();
        $idram = app(IdramGateway::class);

        return response()->json(['data' => [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currency' => 'AMD',
            'income' => [
                'period' => $income,
                'period_online' => $onlineIncome,
                'period_manual' => $income - $onlineIncome,
                'today' => (int) $paid()->whereDate('paid_at', today())->sum('amount'),
                'this_month' => (int) $paid()->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
                'all_time' => (int) $paid()->sum('amount'),
                'paid_invoices_period' => $paid()->whereBetween('paid_at', [$from, $to])->count(),
            ],
            'mrr' => app(\App\Services\Billing\SubscriptionIncome::class)->monthlyRecurringRevenue(),
            'pending' => [
                'invoices' => $this->invoiceQuery()->where('status', 'pending')->count(),
                'amount' => (int) $this->invoiceQuery()->where('status', 'pending')->sum('amount'),
            ],
            'review_required' => $this->reviewQuery()->count(),
            'subscriptions' => [
                'active' => (int) ($subscriptions['active'] ?? 0),
                'trialing' => (int) ($subscriptions['trialing'] ?? 0),
                'expired' => (int) ($subscriptions['expired'] ?? 0),
                'suspended' => (int) ($subscriptions['suspended'] ?? 0),
                'canceled' => (int) ($subscriptions['canceled'] ?? 0),
                'trials_ending_7d' => Subscription::where('status', 'trialing')->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->count(),
                'renewals_due_7d' => Subscription::where('status', 'active')->whereBetween('current_period_ends_at', [now(), now()->addDays(7)])->count(),
            ],
            'provider' => [
                'default' => $providers['default'],
                'display_name' => $providers['display_name'],
                'checkout_available' => $providers['checkout_available'],
                'connection_status' => $providers['connection_status'],
                // Never expose the secret; only whether it is set and which merchant account is used.
                'idram' => [
                    'ready' => $idram->isReady(),
                    'can_verify_callbacks' => $idram->canVerifyCallbacks(),
                    'merchant_account' => (string) config('billing.providers.idram.rec_account') ?: null,
                    'live_enabled' => (bool) config('billing.providers.idram.live_enabled'),
                    'result_url' => rtrim((string) config('app.url'), '/').'/api/payments/idram/result',
                    'success_url' => rtrim((string) config('app.url'), '/').'/api/payments/idram/success',
                    'fail_url' => rtrim((string) config('app.url'), '/').'/api/payments/idram/fail',
                ],
                'last_callback_at' => optional(PaymentWebhookEvent::query()->where('provider', 'idram')->latest('id')->value('created_at'))->toISOString(),
            ],
        ]]);
    }

    public function invoices(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,cancelled,rejected'],
            'business_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = $this->invoiceQuery()
            ->with(['business:id,name,slug,business_type,vertical,status', 'plan:id,name,code',
                'paymentTransactions' => fn ($q) => $q->latest('id')])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['business_id'] ?? null, fn ($q, $id) => $q->where('business_id', $id))
            ->when($data['search'] ?? null, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    if (ctype_digit($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                    $q->orWhereHas('business', fn ($b) => $b->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"))
                        ->orWhereHas('paymentTransactions', fn ($t) => $t->where('provider_transaction_id', $search)->orWhere('provider_payment_id', $search));
                });
            })
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 25);

        $items->getCollection()->transform(fn (Invoice $invoice) => $this->serializeInvoice($invoice));

        return response()->json(['data' => $items]);
    }

    public function transactions(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,paid,failed'],
            'review' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = filter_var($data['review'] ?? false, FILTER_VALIDATE_BOOL) ? $this->reviewQuery() : PaymentTransaction::query();
        $items = $query->with(['business:id,name,slug', 'invoice' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'status', 'amount', 'billing_cycle')])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 25);

        $items->getCollection()->transform(fn (PaymentTransaction $transaction) => $this->serializeTransaction($transaction));

        return response()->json(['data' => $items]);
    }

    /** Activates a plan without an online payment, e.g. after a bank transfer. Always audited. */
    public function approve(Request $request, int $invoice)
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
        $item = $this->invoiceQuery()->findOrFail($invoice);
        abort_unless($item->status === 'pending', 422, 'Only a pending invoice can be approved.');
        $before = $item->only(['status', 'paid_at']);
        app(BillingLifecycleService::class)->approveInvoice($item, ['provider' => 'admin_manual', 'note' => 'Admin: '.$data['note']]);
        $item->refresh();
        $item->update(['payment_method' => $item->payment_method ?: 'manual']);
        $this->audit($request, 'billing_invoice_approved', $item, $before, ['status' => 'approved', 'note' => $data['note']]);

        return response()->json(['data' => $this->serializeInvoice($item->fresh(['business', 'plan', 'paymentTransactions']))]);
    }

    public function cancel(Request $request, int $invoice)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $item = $this->invoiceQuery()->findOrFail($invoice);
        abort_unless($item->status === 'pending', 422, 'Only a pending invoice can be cancelled.');
        DB::transaction(function () use ($item) {
            $item->paymentTransactions()->where('status', 'pending')->update(['status' => 'failed', 'failed_at' => now()]);
            $item->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        });
        $this->audit($request, 'billing_invoice_cancelled', $item, ['status' => 'pending'], ['status' => 'cancelled', 'note' => $data['note'] ?? null]);

        return response()->json(['data' => $this->serializeInvoice($item->fresh(['business', 'plan', 'paymentTransactions']))]);
    }

    /** Marks a received-but-unapplied payment as handled (refunded or applied by hand). */
    public function resolveReview(Request $request, PaymentTransaction $transaction)
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
        abort_unless($transaction->requires_manual_review, 422, 'This payment does not need review.');
        $payload = $transaction->provider_payload ?? [];
        $payload['requires_manual_review'] = false;
        $payload['review_resolved'] = ['at' => now()->toISOString(), 'admin_id' => $request->user()->id, 'note' => $data['note']];
        $transaction->update(['provider_payload' => $payload]);
        $this->audit($request, 'billing_review_resolved', $transaction, ['requires_manual_review' => true], ['note' => $data['note']]);

        return response()->json(['data' => $this->serializeTransaction($transaction->fresh(['business']))]);
    }

    private function invoiceQuery()
    {
        return Invoice::query()->withoutGlobalScopes();
    }

    private function reviewQuery()
    {
        return PaymentTransaction::query()->where('status', 'paid')->where(function ($q) {
            $q->where('provider_payload->requires_manual_review', true)->orWhere('provider_payload->requires_manual_review', 'true');
        });
    }

    private function serializeInvoice(Invoice $invoice): array
    {
        $transaction = $invoice->relationLoaded('paymentTransactions') ? $invoice->paymentTransactions->first() : null;

        return [
            'id' => $invoice->id,
            'status' => $invoice->status,
            'amount' => (int) $invoice->amount,
            'currency' => $invoice->currency,
            'billing_cycle' => $invoice->billing_cycle ?: 'monthly',
            'payment_method' => $invoice->payment_method,
            'note' => $invoice->note,
            'created_at' => optional($invoice->created_at)->toISOString(),
            'paid_at' => optional($invoice->paid_at)->toISOString(),
            'cancelled_at' => optional($invoice->cancelled_at)->toISOString(),
            'business' => $invoice->business ? $invoice->business->only(['id', 'name', 'slug', 'business_type', 'vertical', 'status']) : null,
            'plan' => $invoice->plan ? $invoice->plan->only(['id', 'name', 'code']) : null,
            'transaction' => $transaction ? $this->serializeTransaction($transaction) : null,
            'attempts' => $invoice->relationLoaded('paymentTransactions') ? $invoice->paymentTransactions->count() : null,
        ];
    }

    private function serializeTransaction(PaymentTransaction $transaction): array
    {
        $payload = $transaction->provider_payload ?? [];

        return [
            'id' => $transaction->id,
            'invoice_id' => $transaction->invoice_id,
            'provider' => $transaction->provider,
            'reference' => $transaction->provider_transaction_id,
            'provider_payment_id' => $transaction->provider_payment_id,
            'status' => $transaction->status,
            'amount' => (int) $transaction->amount,
            'currency' => $transaction->currency,
            'payer_account' => $payload['EDP_PAYER_ACCOUNT'] ?? null,
            'requires_manual_review' => $transaction->requires_manual_review,
            'review_resolved' => $payload['review_resolved'] ?? null,
            'duplicate_payment_received' => (bool) ($payload['duplicate_payment_received'] ?? false),
            'created_at' => optional($transaction->created_at)->toISOString(),
            'paid_at' => optional($transaction->paid_at)->toISOString(),
            'failed_at' => optional($transaction->failed_at)->toISOString(),
            'business' => $transaction->business ? $transaction->business->only(['id', 'name', 'slug']) : null,
            'invoice' => $transaction->relationLoaded('invoice') && $transaction->invoice ? $transaction->invoice->only(['id', 'status', 'amount', 'billing_cycle']) : null,
        ];
    }

    private function audit(Request $request, string $action, $model, array $old, array $new): void
    {
        AdminLog::create([
            'admin_id' => $request->user()->id, 'action' => $action, 'model_type' => $model::class, 'model_id' => $model->id,
            'old_values' => $old, 'new_values' => $new, 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
