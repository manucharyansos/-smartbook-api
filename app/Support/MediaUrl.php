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
