<?php

namespace App\Support;

/**
 * Guards spreadsheet output (CSV/XLSX) against formula injection.
 *
 * Cells beginning with =, +, -, @, tab, or carriage return are interpreted
 * as formulas by Excel/Google Sheets when the file is opened. Prefixing a
 * single quote forces plain-text interpretation without altering how the
 * value reads for humans.
 */
class SpreadsheetSafety
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function escapeCell(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        // A lone dash is the exports' empty-value placeholder and cannot
        // start a formula; longer dash-prefixed values (e.g. "-2+3") stay escaped.
        if ($value === '-') {
            return $value;
        }

        if (in_array($value[0], self::DANGEROUS_PREFIXES, true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed> $row
     * @return array<int|string, mixed>
     */
    public static function escapeRow(array $row): array
    {
        return array_map([self::class, 'escapeCell'], $row);
    }
}
