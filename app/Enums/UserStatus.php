<?php

namespace App\Enums;

/**
 * Lifecycle-only user status.
 *
 * These values represent account lifecycle states — NOT session states.
 * Login/logout MUST NOT modify status.
 *
 * Transition rules:
 *   - New user (admin-created)  → Active
 *   - New user (Google OAuth)   → PendingProfile
 *   - Profile completed         → Active
 *   - Admin suspend             → Suspended
 *   - Admin unsuspend           → Active (or PendingProfile if profile incomplete)
 */
enum UserStatus: string
{
    case Active = 'Active';
    case Suspended = 'Suspended';
    case PendingProfile = 'Pending_Profile';

    /**
     * Get all allowed status values as an array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Generate a Laravel validation rule string.
     * Usage: 'status' => 'nullable|' . UserStatus::validationRule()
     */
    public static function validationRule(): string
    {
        return 'in:' . implode(',', self::values());
    }

    /**
     * Human-readable label (Indonesian).
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Suspended => 'Disuspend',
            self::PendingProfile => 'Menunggu Profil',
        };
    }
}
