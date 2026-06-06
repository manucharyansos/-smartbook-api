<?php

namespace App\Support;

class Phone
{
    /**
     * Normalize Armenian phone numbers to E.164 (+374XXXXXXXX).
     * Accepts: 09XXXXXXXX, 091XXXXXX, 098XXXXXX, +3749XXXXXXX, 003749XXXXXXX, etc.
     */
    public static function normalizeAM(?string $raw): ?string
    {
        if (!$raw) return null;

        $s = trim($raw);
        // keep digits and +
        $s = preg_replace('/[^0-9+]/', '', $s);

        if (!$s) return null;

        // 00374 -> +374
        if (str_starts_with($s, '00374')) {
            $s = '+374' . substr($s, 5);
        }

        // If starts with 374 (no +)
        if (str_starts_with($s, '374')) {
            $s = '+'.$s;
        }

        // If starts with 0 and length 9 or 10
        if ($s[0] === '0') {
            // drop leading 0
            $digits = substr($s, 1);
            if (strlen($digits) >= 8 && strlen($digits) <= 9) {
                $s = '+374' . $digits;
            }
        }

        // If only digits and length 8/9 -> assume AM local without leading 0
        if ($s[0] !== '+' && ctype_digit($s) && (strlen($s) === 8 || strlen($s) === 9)) {
            $s = '+374' . $s;
        }

        // final validate: + and 8-12 digits after (AM is +374 + 8 digits)
        if (!preg_match('/^\+\d{8,15}$/', $s)) {
            return null;
        }

        return $s;
    }
}
