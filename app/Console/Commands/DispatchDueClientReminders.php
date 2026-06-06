<?php

namespace App\Console\Commands;

use App\Models\ClientReminder;
use App\Services\ClientReminderDispatchService;
use Illuminate\Console\Command;

class DispatchDueClientReminders extends Command
{
    protected $signature = 'reminders:dispatch-due {--force} {--dry-run}';
    protected $description = 'Dispatch due client reminders';

    public function handle(ClientReminderDispatchService $service): int
    {
        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');

        $reminders = ClientReminder::query()
            ->with(['client.business', 'deliveries'])
            ->where('is_enabled', true)
            ->whereIn('status', ['pending', 'queued'])
            ->get()
            ->filter(fn (ClientReminder $reminder) => $force || $service->isDue($reminder));

        $processed = 0;
        foreach ($reminders as $reminder) {
            if ($dry) {
                $this->line("[dry-run] reminder #{$reminder->id} for client #{$reminder->client_id}");
                $processed++;
                continue;
            }

            $result = $service->dispatch($reminder, $force);
            if (!empty($result['processed'])) {
                $processed++;
            }
        }

        $this->info("Processed reminders: {$processed}");
        return self::SUCCESS;
    }
}
