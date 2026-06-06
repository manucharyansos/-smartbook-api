<?php

namespace App\Support;

class PhoneNormalizer
{
    public static function normalize(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'whatsapp:')) {
            $trimmed = substr($trimmed, 9);
        }

        $normalized = preg_replace('/[^\d+]/', '', $trimmed) ?: '';

        if (str_starts_with($normalized, '00')) {
            $normalized = '+' . substr($normalized, 2);
        }

        if ($normalized !== '' && $normalized[0] !== '+' && preg_match('/^\d+$/', $normalized)) {
            // keep plain numeric strings as-is when country code is unknown
            return $normalized;
        }

        return $normalized !== '' ? $normalized : null;
    }
}
