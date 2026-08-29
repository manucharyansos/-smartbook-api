<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Services\MarketingCampaignService;
use Illuminate\Console\Command;

class DispatchMarketingCampaigns extends Command
{
    protected $signature = 'marketing:dispatch-due';
    protected $description = 'Dispatch scheduled email marketing campaigns';

    public function handle(MarketingCampaignService $service): int
    {
        MarketingCampaign::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->limit(20)
            ->get()
            ->each(fn (MarketingCampaign $campaign) => $service->dispatch($campaign));

        return self::SUCCESS;
    }
}
