<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class SeoController extends Controller
{
    public function meta(Request $request)
    {
        $path = $this->normalizePath((string) $request->query('path', '/'));
        $base = $this->frontendBaseUrl();
        $url = $base . ($path === '/' ? '' : $path);

        if (preg_match('~^/(businesses|book)/([^/?#]+)~', $path, $matches)) {
            $mode = $matches[1];
            $slug = urldecode($matches[2]);
            $business = $this->activeBusiness($slug);

            if ($business) {
                $isBooking = $mode === 'book';
                $title = $isBooking
                    ? sprintf('%s — օնլայն ամրագրում | Vizit', $business->name)
                    : sprintf('%s | Vizit', $business->name);

                $description = $this->businessDescription($business, $isBooking);
                $image = $this->absolutePublicImage($business->cover_url ?: $business->logo_url);

                $canonicalUrl = $isBooking
                    ? $base . '/businesses/' . rawurlencode($business->slug)
                    : $url;
                $payload = $this->payload(
                    title: $title,
                    description: $description,
                    image: $image,
                    url: $canonicalUrl,
                    type: 'website',
                    jsonLd: $this->businessJsonLd($business, $canonicalUrl, $image, $isBooking),
                    robots: $isBooking ? 'noindex,follow' : 'index,follow,max-image-preview:large',
                );
                $payload['url'] = $url;
                $payload['canonical'] = $canonicalUrl;

                return response()->json($payload);
            }
        }

        $static = $this->staticPageMeta($path);
        if ($static) {
            return response()->json($this->payload(
                title: $static['title'],
                description: $static['description'],
                image: $this->defaultImage(),
                url: $url,
                type: 'website',
                jsonLd: $this->siteJsonLd($base),
                robots: $static['robots'] ?? 'index,follow,max-image-preview:large',
            ));
        }

        $payload = $this->payload(
            title: 'Vizit — օնլայն ամրագրում սրահների ու կլինիկաների համար',
            description: 'Vizit-ը օնլայն ամրագրման համակարգ է գեղեցկության սրահների, կլինիկաների և ծառայություն մատուցող բիզնեսների համար Հայաստանում։',
            image: $this->defaultImage(),
            url: $url,
            type: 'website',
            jsonLd: $this->siteJsonLd($base),
            robots: 'noindex,nofollow',
        );
        $payload['status'] = 404;

        return response()->json($payload, 404);
    }

    public function sitemap()
    {
        $base = $this->frontendBaseUrl();
        $urls = [
            ['loc' => $base . '/', 'priority' => '1.0'],
            ['loc' => $base . '/business', 'priority' => '0.9'],
            ['loc' => $base . '/features', 'priority' => '0.8'],
            ['loc' => $base . '/pricing', 'priority' => '0.8'],
            ['loc' => $base . '/about', 'priority' => '0.7'],
            ['loc' => $base . '/contact', 'priority' => '0.7'],
            ['loc' => $base . '/support', 'priority' => '0.6'],
            ['loc' => $base . '/faq', 'priority' => '0.6'],
            ['loc' => $base . '/privacy-policy', 'priority' => '0.4'],
            ['loc' => $base . '/terms', 'priority' => '0.4'],
            ['loc' => $base . '/cookies', 'priority' => '0.4'],
        ];

        $businesses = Business::query()
            ->where('status', 'active')
            ->where('is_onboarding_completed', true);

        $this->applyPublicBusinessExclusions($businesses);

        if (Schema::hasColumn('businesses', 'is_public_profile_enabled')) {
            $businesses->where('is_public_profile_enabled', true);
        } elseif (Schema::hasColumn('businesses', 'is_public')) {
            $businesses->where('is_public', true);
        }

        $businesses->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->each(function (Business $business) use (&$urls, $base) {
                $lastmod = optional($business->updated_at)->toDateString();
                $urls[] = ['loc' => $base . '/businesses/' . rawurlencode($business->slug), 'priority' => '0.8', 'lastmod' => $lastmod];
            });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $item) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . e($item['loc']) . "</loc>\n";
            if (!empty($item['lastmod'])) {
                $xml .= '    <lastmod>' . e($item['lastmod']) . "</lastmod>\n";
            }
            $xml .= '    <changefreq>weekly</changefreq>' . "\n";
            $xml .= '    <priority>' . e($item['priority']) . "</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function activeBusiness(string $slug): ?Business
    {
        $query = Business::query()
            ->with('category')
            ->withCount([
                'services as active_services_count' => fn ($q) => $q->where('is_active', true),
                'users as public_staff_count' => fn ($q) => $q
                    ->where('is_active', true)
                    ->where('show_in_public_team', true)
                    ->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_STAFF]),
            ])
            ->where('slug', $slug)
            ->where('status', 'active')
            ->where('is_onboarding_completed', true);

        $this->applyPublicBusinessExclusions($query);

        if (Schema::hasColumn('businesses', 'is_public_profile_enabled')) {
            $query->where('is_public_profile_enabled', true);
        } elseif (Schema::hasColumn('businesses', 'is_public')) {
            $query->where('is_public', true);
        }

        return $query->first();
    }

    private function payload(string $title, string $description, string $image, string $url, string $type, array $jsonLd, string $robots = 'index,follow,max-image-preview:large'): array
    {
        return [
            'title' => Str::limit($title, 70, ''),
            'description' => Str::limit($this->clean($description), 180, ''),
            'image' => $image,
            'url' => $url,
            'canonical' => $url,
            'site_name' => 'Vizit',
            'type' => $type,
            'locale' => 'hy_AM',
            'robots' => $robots,
            'twitter_card' => 'summary_large_image',
            'json_ld' => $jsonLd,
        ];
    }

    private function businessDescription(Business $business, bool $isBooking): string
    {
        $text = trim((string) ($business->short_description ?: ''));
        if ($text !== '') {
            return $text;
        }

        $type = $business->category?->name_hy ?: ($business->isHealthcareVertical() ? 'բժշկական բիզնես' : 'ծառայությունների բիզնես');
        $serviceCount = (int) ($business->active_services_count ?? 0);
        $staffCount = (int) ($business->public_staff_count ?? 0);

        if ($isBooking) {
            return sprintf('Ամրագրիր այցդ %s-ում օնլայն։ Ընտրիր ծառայություն, մասնագետ և հարմար ժամ։', $business->name);
        }

        $parts = [sprintf('%s-ը %s է Vizit հարթակում։', $business->name, $type)];
        if ($serviceCount > 0) {
            $parts[] = $serviceCount . '+ ծառայություն';
        }
        if ($staffCount > 0) {
            $parts[] = $staffCount . '+ մասնագետ';
        }
        $parts[] = 'Օնլայն ամրագրում՝ արագ և հարմար։';

        return implode(' ', $parts);
    }

    private function businessJsonLd(Business $business, string $url, string $image, bool $isBooking): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => $business->category?->slug === 'dental-clinic' ? 'Dentist' : ($business->isHealthcareVertical() ? 'MedicalBusiness' : 'LocalBusiness'),
            'name' => $business->name,
            'url' => $url,
            'image' => $image,
            'description' => $this->businessDescription($business, $isBooking),
            'telephone' => $business->phone,
            'address' => $business->address,
            'areaServed' => 'Armenia',
            'potentialAction' => [
                '@type' => 'ReserveAction',
                'target' => $this->frontendBaseUrl() . '/book/' . rawurlencode($business->slug),
            ],
        ];
    }

    private function siteJsonLd(string $base): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'Vizit',
            'url' => $base,
            'inLanguage' => 'hy-AM',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => $base . '/?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    private function staticPageMeta(string $path): ?array
    {
        $pages = [
            '/' => ['title' => 'Vizit.am — օնլայն ամրագրում ծառայությունների և բժշկական այցերի համար', 'description' => 'Գտեք ծառայություններն ու բժշկական կենտրոնները, ընտրեք բիզնեսը և ամրագրեք ազատ ժամը Vizit.am-ում։'],
            '/business' => ['title' => 'Vizit բիզնեսների համար — ամրագրումներ և հաճախորդների կառավարում', 'description' => 'Կառավարեք օնլայն ամրագրումները, օրացույցը, թիմը, ծառայություններն ու հաճախորդներին Vizit-ի մեկ հարթակում։'],
            '/features' => ['title' => 'Vizit-ի հնարավորությունները բիզնեսների համար', 'description' => 'Կառավարեք ամրագրումները, օրացույցը, ծառայությունները, թիմը և հաճախորդներին մեկ հարթակում։'],
            '/pricing' => ['title' => 'Vizit-ի գնային պլանները', 'description' => 'Ընտրեք ձեր բիզնեսի չափին համապատասխան Vizit պլանը և սկսեք 14-օրյա փորձաշրջանը։'],
            '/about' => ['title' => 'Vizit-ի մասին', 'description' => 'Ծանոթացեք Vizit.am օնլայն ամրագրման հարթակին և մեր նպատակին։'],
            '/contact' => ['title' => 'Կապ Vizit-ի թիմի հետ', 'description' => 'Գրեք կամ զանգահարեք Vizit-ի թիմին համագործակցության, միացման և այլ հարցերով։'],
            '/support' => ['title' => 'Vizit աջակցություն', 'description' => 'Ստացեք օգնություն Vizit-ի կարգավորումների, վճարումների և ամրագրման հոսքերի վերաբերյալ։'],
            '/faq' => ['title' => 'Հաճախ տրվող հարցեր | Vizit', 'description' => 'Vizit.am-ի գրանցման, ամրագրումների, պլանների և աշխատանքի մասին հաճախ տրվող հարցերի պատասխաններ։'],
            '/privacy-policy' => ['title' => 'Գաղտնիության քաղաքականություն | Vizit', 'description' => 'Կարդացեք Vizit-ի գաղտնիության քաղաքականությունը։'],
            '/terms' => ['title' => 'Օգտագործման պայմաններ | Vizit', 'description' => 'Կարդացեք Vizit հարթակի օգտագործման պայմանները։'],
            '/cookies' => ['title' => 'Cookie-ների քաղաքականություն | Vizit', 'description' => 'Իմացեք, թե ինչպես է Vizit-ը օգտագործում cookie-ները։'],
            '/blog' => ['title' => 'Vizit Blog', 'description' => 'Vizit-ի հոդվածներն ու նորությունները։', 'robots' => 'noindex,follow'],
            '/careers' => ['title' => 'Աշխատանք Vizit-ում', 'description' => 'Vizit-ի թափուր հաստիքներն ու համագործակցության հնարավորությունները։', 'robots' => 'noindex,follow'],
            '/press' => ['title' => 'Vizit մամուլի կենտրոն', 'description' => 'Vizit-ի մամուլի և բրենդային նյութերը։', 'robots' => 'noindex,follow'],
        ];

        foreach (['/login', '/register', '/forgot-password', '/reset-password', '/business/login', '/business/register', '/client/', '/admin', '/app', '/payment-return', '/auth/', '/mock-bank'] as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return [
                    'title' => 'Vizit',
                    'description' => 'Vizit հաշվի անվտանգ մուտք և կառավարում։',
                    'robots' => 'noindex,nofollow',
                ];
            }
        }

        return $pages[$path] ?? null;
    }

    private function applyPublicBusinessExclusions($query): void
    {
        $excluded = array_values(array_filter((array) config('services.public_booking.excluded_slugs', [])));
        if ($excluded) {
            $query->whereNotIn('slug', $excluded);
        }

        foreach ((array) config('services.public_booking.excluded_slug_prefixes', []) as $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix !== '') {
                $query->where('slug', 'not like', $prefix . '%');
            }
        }
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/');
        if ($path === '//' || $path === '/') {
            return '/';
        }

        return rtrim($path, '/');
    }

    private function frontendBaseUrl(): string
    {
        return rtrim((string) config('services.public_booking.frontend_url', 'https://vizit.am'), '/');
    }

    private function defaultImage(): string
    {
        return $this->frontendBaseUrl() . '/og-default.svg';
    }

    private function absolutePublicImage(?string $value): string
    {
        $media = MediaUrl::absolute($value);
        if (!$media) {
            return $this->defaultImage();
        }

        if (Str::startsWith($media, ['http://', 'https://'])) {
            return $media;
        }

        if (Str::startsWith($media, '/api/')) {
            return rtrim((string) config('app.url', 'https://api.vizit.am'), '/') . $media;
        }

        return $this->frontendBaseUrl() . '/' . ltrim($media, '/');
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?: '');
    }
}
