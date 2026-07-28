<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database-managed automation switch for letter retention. Kept on the existing
 * single-row global policy table so the SuperAdmin ON/OFF setting lives in the
 * database (never in .env) alongside — but logically separate from — the
 * retention-day policy. Run telemetry columns give the UI honest evidence of
 * whether the scheduled command has actually executed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_retention_policies', function (Blueprint $table): void {
            $table->boolean('automation_enabled')->default(false)->after('archive_retention_days');
            $table->foreignId('automation_updated_by')->nullable()->after('automation_enabled')->constrained('users')->nullOnDelete();
            $table->timestamp('automation_enabled_at')->nullable()->after('automation_updated_by');
            $table->timestamp('automation_disabled_at')->nullable()->after('automation_enabled_at');
            // Telemetry — kept unambiguous:
            //   last_checked_at : command woke and evaluated the DB gate (even if skipped).
            //   last_run_at     : an ENABLED execution actually started.
            //   last_success_at : an ENABLED execution completed successfully.
            //   last_failure_at/message : an ENABLED execution failed.
            $table->timestamp('last_checked_at')->nullable()->after('automation_disabled_at');
            $table->timestamp('last_run_at')->nullable()->after('last_checked_at');
            $table->timestamp('last_success_at')->nullable()->after('last_run_at');
            $table->timestamp('last_failure_at')->nullable()->after('last_success_at');
            $table->string('last_failure_message')->nullable()->after('last_failure_at');
        });
    }

    public function down(): void
    {
        Schema::table('letter_retention_policies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('automation_updated_by');
            $table->dropColumn([
                'automation_enabled',
                'automation_enabled_at',
                'automation_disabled_at',
                'last_checked_at',
                'last_run_at',
                'last_success_at',
                'last_failure_at',
                'last_failure_message',
            ]);
        });
    }
};
