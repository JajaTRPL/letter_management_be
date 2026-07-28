<?php

namespace App\Enums;

/**
 * Notification priority. `sortWeight()` drives the API ordering so that
 * unresolved urgent/action items surface above reminders, updates, and
 * resolved history without a second query.
 */
enum NotificationPriority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    /** Lower weight sorts first. */
    public function sortWeight(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::High => 1,
            self::Normal => 2,
            self::Low => 3,
        };
    }
}
