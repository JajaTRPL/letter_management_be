<?php

namespace App\Helpers;

/**
 * Centralized date normalization utilities.
 * Extracted from UserController::normalizeDate() to enable reuse across import pipelines.
 */
class DateHelper
{
    /**
     * Normalize date string from multiple formats to Y-m-d.
     * Accepts: YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY
     *
     * @param  string|null $value Raw date string
     * @return string|null        Normalized Y-m-d string, or null if unrecognized
     */
    public static function normalizeDate(?string $value): ?string
    {
        if (!$value || empty(trim($value))) return null;
        $value = trim($value);

        // Already Y-m-d
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;

        // DD/MM/YYYY or DD-MM-YYYY
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $value, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return null; // unrecognized format
    }
}
