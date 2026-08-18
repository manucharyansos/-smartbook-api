<?php

namespace App\Support;

class MediaUrl
{
    public static function absolute(?string $value): ?string
    {
        if (!$value) {
            return $value;
        }

        if (preg_match('/^https?:\/\//i', $value)) {
            $parts = parse_url($value);
            $path = $parts['path'] ?? null;
            if (!$path) {
                return $value;
            }

            $valueHost = strtolower((string) ($parts['host'] ?? ''));
            $appHost = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''));
            if ($valueHost !== '' && $appHost !== '' && $valueHost !== $appHost) {
                return $value;
            }

            return self::absolute($path);
        }

        $path = trim((string) $value);
        $path = preg_replace('#^/?storage/#', '', $path) ?? $path;
        $path = preg_replace('#^/?api/media/file/#', '', $path) ?? $path;
        $path = ltrim($path, '/');

        if ($path === '') {
            return null;
        }

        $encoded = implode('/', array_map(
            'rawurlencode',
            array_filter(explode('/', $path), fn ($seg) => $seg !== '')
        ));

        return '/api/media/file/' . $encoded;
    }
}
