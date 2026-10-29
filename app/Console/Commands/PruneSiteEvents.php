<?php

namespace App\Console\Commands;

use App\Models\SiteEvent;
use Illuminate\Console\Command;

class PruneSiteEvents extends Command
{
    protected $signature = 'vizit:prune-site-events {--days=400}';

    protected $description = 'Delete anonymous site analytics events older than the retention period';

    public function handle(): int
    {
        $deleted = SiteEvent::query()->where('created_at', '<', now()->subDays(max(30, (int) $this->option('days'))))->delete();
        $this->info("Deleted {$deleted} events.");

        return self::SUCCESS;
    }
}
