<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

/**
 * Centralized service for logging admin/system actions.
 * Replaces 6+ identical ActivityLog::create() blocks throughout the codebase.
 */
class ActivityLogService
{
    /**
     * Log an admin action.
     *
     * @param string      $action     Short action label (e.g. 'Tambah User')
     * @param string      $targetUser Target user email or identifier
     * @param string      $details    Human-readable description of the action
     * @param string|null $type       Log type — defaults to 'admin'
     */
    public static function log(
        string $action,
        string $targetUser,
        string $details,
        string $type = 'admin'
    ): void {
        ActivityLog::create([
            'user_id'     => Auth::id(),
            'type'        => $type,
            'action'      => $action,
            'target_user' => $targetUser,
            'details'     => $details,
            'ip_address'  => request()->ip(),
            'user_agent'  => request()->userAgent(),
        ]);
    }
}
