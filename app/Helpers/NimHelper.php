<?php

namespace App\Helpers;

/**
 * Centralized NIM processing helper.
 * Single source of truth for normalization, validation, and angkatan derivation.
 */
class NimHelper
{
    /**
     * Normalize NIM: trim whitespace and convert to uppercase.
     */
    public static function normalize(?string $nim): ?string
    {
        if ($nim === null || trim($nim) === '') {
            return null;
        }

        return strtoupper(trim($nim));
    }

    /**
     * Validate NIM format.
     * Expected UGM format: YY/XXXXXX/XX/XXXXX (e.g. 24/535278/SV/12345)
     * Flexible: allows variations in segment lengths.
     */
    public static function validate(?string $nim): bool
    {
        if ($nim === null || trim($nim) === '') {
            return false;
        }

        // Pattern: 2-digit year / digits / 2-5 uppercase letters / digits
        return (bool) preg_match('/^\d{2}\/\d+\/[A-Z]{2,5}\/\d+$/', strtoupper(trim($nim)));
    }

    /**
     * Derive angkatan (enrollment year) from NIM prefix.
     * E.g. "24/535278/SV/12345" → "2024"
     *       "99/123/TK/456"     → "1999"
     */
    public static function deriveAngkatan(?string $nim): string
    {
        if ($nim === null || strlen($nim) < 2) {
            return '-';
        }

        if (preg_match('/^(\d{2})/', $nim, $matches)) {
            $yearPrefix = (int) $matches[1];
            return $yearPrefix > 30 ? "19{$matches[1]}" : "20{$matches[1]}";
        }

        return '-';
    }
}
