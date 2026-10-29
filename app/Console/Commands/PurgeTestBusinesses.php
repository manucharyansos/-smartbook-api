<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\BusinessPurgeService;
use Illuminate\Console\Command;

class PurgeTestBusinesses extends Command
{
    protected $signature = 'vizit:purge-businesses
        {slugs* : Slugs of the test businesses to remove}
        {--dry-run : Only show what would be removed}
        {--force : Do not ask for confirmation (for scripts)}';

    protected $description = 'Permanently remove test businesses and all their data (shows a preview first)';

    public function handle(BusinessPurgeService $purger): int
    {
        $businesses = Business::query()->whereIn('slug', $this->argument('slugs'))->get();
        $missing = array_diff($this->argument('slugs'), $businesses->pluck('slug')->all());
        if ($missing) {
            $this->error('Not found: '.implode(', ', $missing).'. Nothing was changed.');

            return self::FAILURE;
        }

        foreach ($businesses as $business) {
            $preview = $purger->preview($business);
            $this->info("#{$business->id} {$business->name} ({$business->slug}, {$business->status})");
            $this->table(['Data', 'Rows'], collect($preview['counts'])->map(fn ($count, $key) => [$key, $count])->values()->all());
            foreach ($preview['warnings'] as $warning) {
                $this->warn($warning);
            }
        }

        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('Permanently delete the businesses above? This cannot be undone.')) {
            $this->line('Cancelled. Nothing was deleted.');

            return self::SUCCESS;
        }

        foreach ($businesses as $business) {
            $purger->purge($business);
            $this->info("Deleted {$business->slug}.");
        }

        return self::SUCCESS;
    }
}
