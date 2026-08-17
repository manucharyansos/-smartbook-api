<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class BillingLifecycleService
{
    public function approveInvoice(Invoice $invoice, array $paymentMeta = []): Invoice
    {
        return DB::transaction(function () use ($invoice, $paymentMeta) {
            $invoice->loadMissing(['plan', 'business.subscription.plan']);

            if (in_array($invoice->status, ['approved', 'paid'], true)) {
                return $invoice;
            }

            if ($invoice->status !== 'pending') {
                throw new \DomainException('Only pending invoices can be approved.');
            }

            $plan = $invoice->plan;
            $business = $invoice->business;
            $invoiceMeta = is_array($invoice->meta) ? $invoice->meta : [];
            $periodDays = (int) ($invoiceMeta['period_days'] ?? ($plan?->duration_days ?: 30));
            $billingCycle = (string) ($invoice->billing_cycle ?: ($invoiceMeta['billing_cycle'] ?? 'monthly'));

            $now = now();
            $sub = Subscription::firstOrNew(['business_id' => $invoice->business_id]);
            $hasFuturePeriod = $sub->exists
                && $sub->current_period_ends_at
                && $now->lt($sub->current_period_ends_at);
            $periodBase = $hasFuturePeriod
                ? $sub->current_period_ends_at->copy()
                : $now->copy();

            if ($plan) {
                $sub->applyPlanSnapshot($plan);
            }

            $sub->fill([
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => $billingCycle,
                'trial_ends_at' => null,
                'canceled_at' => null,
                'suspended_at' => null,
                'cancel_at_period_end' => false,
                'provider' => $paymentMeta['provider'] ?? $sub->provider,
                'provider_customer_id' => $paymentMeta['provider_customer_id'] ?? $sub->provider_customer_id,
                'provider_subscription_id' => $paymentMeta['provider_subscription_id'] ?? $sub->provider_subscription_id,
            ]);

            if (!$hasFuturePeriod || !$sub->current_period_starts_at) {
                $sub->current_period_starts_at = $now;
            }
            $sub->current_period_ends_at = $periodBase->addDays($periodDays);

            $sub->save();

            $invoice->update([
                'status' => 'approved',
                'paid_at' => now(),
                'note' => trim(((string) $invoice->note) . ' ' . ($paymentMeta['note'] ?? '')) ?: null,
                'billing_cycle' => $billingCycle,
            ]);

            if ($business) {
                $business->update([
                    'billing_status' => 'active',
                    'suspended_at' => null,
                ]);
            }

            return $invoice->fresh(['plan', 'business', 'business.subscription']);
        });
    }
}
