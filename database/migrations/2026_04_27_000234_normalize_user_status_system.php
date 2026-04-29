<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Normalize user status system:
     * - Add last_login_at for activity tracking (replaces login/logout status toggling)
     * - Rename 'Inactive' → 'Active' (Inactive was just "logged out", not a real state)
     * - Rename 'Blocked' → 'Suspended' (standardize naming)
     * - Rename 'pending_profile' → 'Pending_Profile' (consistent casing)
     */
    public function up(): void
    {
        // 1. Add last_login_at column
        if (!Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_login_at')->nullable()->after('status');
            });
        }

        // 2. Force change column default and normalize values
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('Active')->change();
        });

        DB::table('users')->where('status', 'Inactive')->update(['status' => 'Active']);
        DB::table('users')->where('status', 'Blocked')->update(['status' => 'Suspended']);
        DB::table('users')->where('status', 'pending_profile')->update(['status' => 'Pending_Profile']);
    }

    public function down(): void
    {
        // Reverse status normalization
        DB::table('users')->where('status', 'Suspended')->update(['status' => 'Blocked']);
        DB::table('users')->where('status', 'Pending_Profile')->update(['status' => 'pending_profile']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
