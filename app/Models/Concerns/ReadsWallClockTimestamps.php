<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * For models whose columns are `timestamptz` but are written the Laravel
 * default way — as a naive 'Y-m-d H:i:s' wall-clock string in app timezone.
 *
 * PostgreSQL stores such a string in its session timezone (UTC on the server)
 * and returns it with that offset, e.g. a 16:25 WIB value comes back as
 * "16:25:00+00". Honouring the offset would shift it to 23:25 WIB. Every write
 * and every SQL comparison binding uses the same naive wall-clock form, so the
 * stored wall clock is the truth: read it as app-timezone time and drop the
 * offset. Values without an offset (SQLite, a Jakarta session) are unchanged.
 */
trait ReadsWallClockTimestamps
{
    private const WALL_CLOCK_WITH_OFFSET = '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}(?::?\d{2})?)$/';

    protected function asDateTime($value)
    {
        if (is_string($value) && preg_match(self::WALL_CLOCK_WITH_OFFSET, $value, $match)) {
            return Carbon::createFromFormat('Y-m-d H:i:s', "{$match[1]} {$match[2]}", config('app.timezone'));
        }

        return parent::asDateTime($value);
    }
}
