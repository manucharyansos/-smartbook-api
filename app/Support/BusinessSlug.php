<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessSlugAlias;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Public booking links: vizit.am/book/<slug>.
 *
 * The link follows the business name. When the name changes, the previous link is kept as an
 * alias, so links already shared on social media or in QR codes keep opening the business.
 *
 * Two kinds of addresses are kept off the public site (config services.public_booking):
 * - excluded_slugs: exact addresses of old test businesses. They are never handed out again.
 * - excluded_slug_prefixes: QA/E2E businesses get these on purpose. Their link never moves,
 *   so they stay hidden whatever they are renamed to.
 */
class BusinessSlug
{
    private static bool $aliasTableReady = false;

    public static function fromName(string $name): string
    {
        return Str::slug($name);
    }

    /** One of the exact addresses kept off the public site. */
    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::configList('excluded_slugs'), true);
    }

    /** A QA/E2E address, hidden from the public site by its prefix. */
    public static function isQa(string $slug): bool
    {
        foreach (self::configList('excluded_slug_prefixes') as $prefix) {
            if (str_starts_with($slug, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** A free link for this name. $businessId may take back its own current or previous link. */
    public static function unique(string $name, ?int $businessId = null): string
    {
        $base = self::fromName($name) ?: 'business';
        $slug = $base;
        $counter = 1;

        while (self::isTaken($slug, $businessId)) {
            $counter++;
            $slug = $base . '-' . $counter;
        }

        return $slug;
    }

    /**
     * Gives the business a link made from its current name and keeps the previous link as an
     * alias. Nothing changes when the current link already comes from this name, the name gives
     * no usable link, or the business is a QA one. A reserved current link (e.g. "test") is
     * always replaced: with it the business could never be public.
     */
    public static function follow(Business $business): bool
    {
        $current = (string) $business->slug;
        $name = (string) $business->name;
        $reserved = $current !== '' && self::isReserved($current);

        if (self::fromName($name) === '' || ($current !== '' && self::isQa($current))) {
            return false;
        }
        if ($current !== '' && !$reserved && self::comesFrom($current, $name)) {
            return false;
        }
        // Without the alias table the old link would stop working, so wait for the migration.
        if ($current !== '' && !$reserved && !self::aliasTableReady()) {
            return false;
        }

        $next = self::unique($name, $business->id);
        if ($next === $current) {
            return false;
        }

        $business->forceFill(['slug' => $next])->save();

        if (self::aliasTableReady()) {
            BusinessSlugAlias::query()->where('slug', $next)->delete();
            if ($current !== '' && !$reserved) {
                BusinessSlugAlias::query()->updateOrCreate(['slug' => $current], ['business_id' => $business->id]);
            }
        }

        return true;
    }

    /** The current link of the business that a link (possibly a previous one) points to. */
    public static function resolve(string $slug): string
    {
        if (!self::aliasTableReady() || Business::query()->where('slug', $slug)->exists()) {
            return $slug;
        }

        $businessId = BusinessSlugAlias::query()->where('slug', $slug)->value('business_id');
        $current = $businessId ? Business::query()->whereKey($businessId)->value('slug') : null;

        return $current ? (string) $current : $slug;
    }

    /** True when the link is the name's own link or one of its numbered variants (salon, salon-2). */
    public static function comesFrom(string $slug, string $name): bool
    {
        $base = self::fromName($name);

        return $base !== '' && ($slug === $base || preg_match('/^' . preg_quote($base, '/') . '-\d+$/', $slug) === 1);
    }

    private static function isTaken(string $slug, ?int $businessId): bool
    {
        if (self::isReserved($slug)) {
            return true;
        }

        $usedByBusiness = Business::query()->where('slug', $slug)
            ->when($businessId, fn ($query) => $query->whereKeyNot($businessId))
            ->exists();
        if ($usedByBusiness) {
            return true;
        }

        return self::aliasTableReady() && BusinessSlugAlias::query()->where('slug', $slug)
            ->when($businessId, fn ($query) => $query->where('business_id', '!=', $businessId))
            ->exists();
    }

    private static function aliasTableReady(): bool
    {
        return self::$aliasTableReady = self::$aliasTableReady || Schema::hasTable('business_slug_aliases');
    }

    private static function configList(string $key): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            (array) config('services.public_booking.' . $key, [])
        )));
    }
}
