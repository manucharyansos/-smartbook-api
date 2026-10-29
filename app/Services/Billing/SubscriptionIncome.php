<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Subscription;

final class SubscriptionIncome
{
    /** Monthly value of active, paid subscriptions, from what each business actually last paid (yearly / 12). */
    public function monthlyRecurringRevenue(): int
    {
        $businessIds = Subscription::where('status', 'active')->pluck('business_id');
        if ($businessIds->isEmpty()) {
            return 0;
        }
        $latest = Invoice::query()->withoutGlobalScopes()->where('status', 'approved')->where('amount', '>', 0)
            ->whereIn('business_id', $businessIds)->orderByDesc('paid_at')->orderByDesc('id')
            ->get(['business_id', 'amount', 'billing_cycle'])->unique('business_id');

        return (int) round($latest->sum(fn (Invoice $invoice) => $invoice->billing_cycle === 'yearly' ? $invoice->amount / 12 : $invoice->amount));
    }
}
