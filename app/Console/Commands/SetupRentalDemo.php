<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Support\MediaUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SetupRentalDemo extends Command
{
    protected $signature = 'rental:setup-demo {slug : Existing demo space slug} {--owner-email= : Registered owner email} {--dry-run : Validate without changing data}';

    protected $description = 'Configure an existing test rental space with demo media and flexible booking; preserve accounts and bookings';

    public function handle(): int
    {
        $business = Business::query()->where('slug', $this->argument('slug'))->first();
        $email = Str::lower(trim((string) $this->option('owner-email')));
        $demoName = $business && Str::startsWith(Str::upper(trim($business->name)), ['ԹԵՍՏ ', 'TEST ', 'DEMO ']);
        if (! $business?->isRental() || ! $demoName || ! $email
            || ! $business->users()->where('role', 'owner')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error('No matching demo rental and owner. Nothing changed.');

            return self::FAILURE;
        }
        $assets = resource_path('demo/rental-space');
        foreach (['logo.png', 'cover.png', 'gallery-workshop.png', 'gallery-lounge.png'] as $name) {
            if (! is_file($assets.'/'.$name)) {
                $this->error('Missing demo asset: '.$name.'. Nothing changed.');

                return self::FAILURE;
            }
        }
        if ($this->option('dry-run')) {
            $this->info('Demo validated: 5,000 AMD/hour; 1 hour–7 days; daily 10:00–22:00; 15-minute buffer. Existing media, accounts and bookings are preserved.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($business, $assets) {
            $business = Business::query()->lockForUpdate()->findOrFail($business->id);
            $prefix = 'businesses/'.$business->id.'/rental-demo/';
            $media = [];
            foreach (['logo_url' => 'logo.png', 'cover_url' => 'cover.png'] as $field => $name) {
                if (! $business->{$field}) {
                    if (! Storage::disk('public')->put($prefix.$name, file_get_contents($assets.'/'.$name))) {
                        throw new \RuntimeException('Could not store demo image.');
                    }
                    $media[$field] = MediaUrl::absolute($prefix.$name);
                }
            }
            $business->update([
                ...$media, 'show_logo' => true, 'show_cover' => true, 'show_services' => false, 'show_staff' => false,
                'rental_hourly_price' => 5000, 'rental_min_minutes' => 60, 'rental_max_minutes' => 10080,
                'rental_allow_overnight' => true, 'rental_buffer_minutes' => 15, 'slot_step_minutes' => 30,
                'work_start' => '10:00', 'work_end' => '22:00',
                'short_description' => 'Փորձնական տարածք՝ հանդիպումների, դասընթացների և փոքր միջոցառումների համար։',
                'description' => "Սա Vizit-ի փորձնական վարձով տարածքն է։ Տարածքը, հասցեն և պատկերները ցուցադրական են․ իրական վարձակալություն չի առաջարկվում։\n\nԺամավարձ՝ 5,000 դրամ։ Ընտրեք 1 ժամից մինչև 7 օր։ Մեկ օրը 24 անընդմեջ ժամ է․ գիշերային ժամերը նույնպես ներառվում են գնի և զբաղվածության մեջ։ Սկզբի և ավարտի ժամերը՝ ամեն օր 10:00–22:00։ Ամրագրումների միջև՝ 15 րոպե ընդմիջում։",
            ]);
            for ($weekday = 1; $weekday <= 7; $weekday++) {
                $business->workingHours()->updateOrCreate(['weekday' => $weekday], [
                    'is_closed' => false, 'start' => '10:00', 'end' => '22:00', 'break_start' => null, 'break_end' => null,
                ]);
            }
            foreach (['gallery-workshop.png' => 'Ցուցադրական պատկեր · դասընթացների սրահ', 'gallery-lounge.png' => 'Ցուցադրական պատկեր · հանգստի գոտի'] as $name => $caption) {
                if ($business->gallery()->where('path', $prefix.$name)->exists() || $business->gallery()->count() >= 20) {
                    continue;
                }
                if (! Storage::disk('public')->put($prefix.$name, file_get_contents($assets.'/'.$name))) {
                    throw new \RuntimeException('Could not store demo gallery image.');
                }
                $business->gallery()->create(['path' => $prefix.$name, 'mime_type' => 'image/png', 'kind' => 'image', 'caption' => $caption, 'sort_order' => (int) $business->gallery()->max('sort_order') + 1]);
            }
        });
        $this->info('Demo ready: /businesses/'.$business->slug.' | /book/'.$business->slug);

        return self::SUCCESS;
    }
}
