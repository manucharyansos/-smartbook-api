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

            $plan = $invoice->plan;
            $business = $invoice->business;
            $invoiceMeta = is_array($invoice->meta) ? $invoice->meta : [];
            $periodDays = (int) ($invoiceMeta['period_days'] ?? ($plan?->duration_days ?: 30));
            $billingCycle = (string) ($invoice->billing_cycle ?: ($invoiceMeta['billing_cycle'] ?? 'monthly'));

            $sub = Subscription::firstOrCreate(
                ['business_id' => $invoice->business_id],
                [
                    'plan_id' => $invoice->plan_id,
                    'status' => Subscription::STATUS_ACTIVE,
                    'billing_cycle' => $billingCycle,
                    'current_period_starts_at' => now(),
                    'current_period_ends_at' => now()->addDays($periodDays),
                ]
            );

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

            if (!$sub->current_period_starts_at) {
                $sub->current_period_starts_at = now();
            }
            if (!$sub->current_period_ends_at || now()->gte($sub->current_period_ends_at)) {
                $sub->current_period_ends_at = now()->addDays($periodDays);
            }

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
