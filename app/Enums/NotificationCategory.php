<?php

namespace App\Enums;

/**
 * The four durable notification categories. `action_required` and `reminder`
 * are the actionable families whose lifecycle can be resolved by a later
 * domain transition; `update` and `system` are informational.
 */
enum NotificationCategory: string
{
    case ActionRequired = 'action_required';
    case Reminder = 'reminder';
    case Update = 'update';
    case System = 'system';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    /** Categories whose notifications carry an action that a later event resolves. */
    public function isActionable(): bool
    {
        return $this === self::ActionRequired || $this === self::Reminder;
    }
}
