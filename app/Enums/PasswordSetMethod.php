<?php

namespace App\Enums;

enum PasswordSetMethod: string
{
    case ResetPasswordOtp = 'reset_password_otp';
    case SuperAdminSet = 'super_admin_set';
    case SelfServiceChange = 'self_service_change';
    case LegacyUnknown = 'legacy_unknown';
    case TemporaryAdmin = 'temporary_admin';
    case SystemMigration = 'system_migration';
    case SystemSeed = 'system_seed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
