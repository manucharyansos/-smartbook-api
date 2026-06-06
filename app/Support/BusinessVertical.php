<?php

namespace App\Support;

final class BusinessVertical
{
    public const SERVICES = 'services';
    public const HEALTHCARE = 'healthcare';

    /**
     * Legacy and public aliases accepted by old frontend/API clients.
     */
    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            self::HEALTHCARE, 'medical', 'clinic', 'dental', 'doctor', 'health' => self::HEALTHCARE,
            default => self::SERVICES,
        };
    }

    public static function fromBusinessType(?string $businessType): string
    {
        return self::normalize($businessType);
    }

    public static function legacyBusinessType(?string $vertical): string
    {
        return self::normalize($vertical) === self::HEALTHCARE ? 'dental' : 'beauty';
    }

    public static function canonicalBusinessType(?string $value): string
    {
        return self::normalize($value);
    }

    public static function isHealthcare(?string $value): bool
    {
        return self::normalize($value) === self::HEALTHCARE;
    }

    public static function values(): array
    {
        return [self::SERVICES, self::HEALTHCARE];
    }

    public static function legacyValues(): array
    {
        return ['beauty', 'dental', 'salon', 'clinic'];
    }
}
