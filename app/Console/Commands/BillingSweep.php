<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Invoice;
use Illuminate\Console\Command;

class BillingSweep extends Command
{
    protected $signature = 'billing:sweep {--dry-run}';
    protected $description = 'Auto-suspend businesses when subscription inactive or invoices stale';

    public function handle(): int
    {
        $dry = (bool)$this->option('dry-run');

        // 1) Subscription inactive → suspend billing
        $businesses = Business::query()
            ->with(['subscription'])
            ->where('billing_status', 'active')
            ->get();

        $suspended = 0;

        foreach ($businesses as $business) {
            $sub = $business->subscription;

            $isActive = $sub && $sub->isActive();
            if (!$isActive) {
                if (!$dry) {
                    $business->update(['billing_status' => 'suspended', 'suspended_at' => now()]);
                }
                $suspended++;
            }
        }

        // 2) Pending invoice older than 48h → suspend billing (optional)
        $staleInvoices = Invoice::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subHours(48))
            ->with('business:id,billing_status')
            ->get();

        $staleSuspended = 0;
        foreach ($staleInvoices as $inv) {
            if ($inv->business && $inv->business->billing_status === 'active') {
                if (!$dry) {
                    $inv->business->update(['billing_status' => 'suspended', 'suspended_at' => now()]);
                }
                $staleSuspended++;
            }
        }

        $this->info("Suspended (subscription inactive): {$suspended}");
        $this->info("Suspended (stale invoices): {$staleSuspended}");

        return self::SUCCESS;
    }
}
