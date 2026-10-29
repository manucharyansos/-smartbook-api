<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\SiteEvent;
use Illuminate\Http\Request;

/**
 * Receives anonymous usage events from the website and the app. Stores no IP
 * address, cookie or account: visitors are a hash that changes every day.
 */
class SiteAnalyticsController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'source' => ['nullable', 'in:web,mobile'],
            'device' => ['nullable', 'in:mobile,tablet,desktop'],
            'events' => ['required', 'array', 'min:1', 'max:20'],
            'events.*.event' => ['required', 'string', 'in:'.implode(',', SiteEvent::EVENTS)],
            'events.*.path' => ['nullable', 'string', 'max:500'],
            'events.*.referrer' => ['nullable', 'string', 'max:500'],
            'events.*.utm_source' => ['nullable', 'string', 'max:60'],
            'events.*.locale' => ['nullable', 'string', 'max:5'],
        ]);

        $agent = (string) $request->userAgent();
        if ($agent === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|monitor/i', $agent)) {
            return response()->noContent();
        }

        // Only used inside the hash; a proxy header is fine here because nothing is authorised by it.
        $address = $request->header('CF-Connecting-IP') ?: trim(explode(',', (string) $request->header('X-Forwarded-For'))[0]) ?: $request->ip();
        $visitor = md5(config('app.key').'|'.now()->toDateString().'|'.$address.'|'.$agent);
        $device = $data['device'] ?? (preg_match('/ipad|tablet/i', $agent) ? 'tablet' : (preg_match('/mobi|android|iphone/i', $agent) ? 'mobile' : 'desktop'));
        $ownHosts = array_values(array_unique(array_map(fn ($host) => preg_replace('/^www\./', '', strtolower((string) $host)), array_filter([
            'vizit.am', 'api.vizit.am', parse_url((string) config('app.frontend_url'), PHP_URL_HOST), parse_url((string) config('app.url'), PHP_URL_HOST),
        ]))));
        $slugs = [];

        $rows = collect($data['events'])->map(function (array $item) use ($data, $visitor, $device, $ownHosts, &$slugs) {
            $path = $item['path'] ?? null;
            $path = $path !== null ? substr('/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/'), 0, 255) : null;
            $referrer = isset($item['referrer']) ? strtolower((string) parse_url($item['referrer'], PHP_URL_HOST)) : '';
            $referrer = preg_replace('/^www\./', '', $referrer);
            $slug = $path && preg_match('#^/(?:business(?:es)?|book)/([a-z0-9-]{1,120})#i', $path, $match) ? strtolower($match[1]) : null;
            if ($slug) {
                $slugs[$slug] = true;
            }

            return [
                'event' => $item['event'], 'path' => $path, 'slug' => $slug, 'visitor' => $visitor,
                'source' => $data['source'] ?? 'web', 'device' => $device,
                'referrer_host' => $referrer && ! in_array($referrer, $ownHosts, true) ? substr($referrer, 0, 100) : null,
                'utm_source' => isset($item['utm_source']) ? substr(strtolower(trim($item['utm_source'])), 0, 60) ?: null : null,
                'locale' => isset($item['locale']) ? substr($item['locale'], 0, 5) : null,
                'created_at' => now(),
            ];
        });

        $businessIds = $slugs ? Business::query()->whereIn('slug', array_keys($slugs))->pluck('id', 'slug') : collect();
        SiteEvent::query()->insert($rows->map(function (array $row) use ($businessIds) {
            $row['business_id'] = $row['slug'] ? ($businessIds[$row['slug']] ?? null) : null;
            unset($row['slug']);

            return $row;
        })->all());

        return response()->noContent();
    }
}
