<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SuperAdmin-governed review-SLA policy (C10). One row per workflow domain
 * ("scope", e.g. room_booking), mirroring the letter_retention_policies
 * governance pattern: database-managed so policy changes need no code deploy,
 * validated + bounded at the write seam, and fully audited (who toggled/edited,
 * and when it was enabled/disabled).
 *
 * Ships DISABLED so it changes NO behavior until a SuperAdmin explicitly turns
 * it on — no retroactive escalation, no notification storm on install. All
 * thresholds are MINUTES from the moment a request entered its current
 * "waiting for review" state. Invariant (enforced in the service/controller):
 * warning_minutes <= overdue_minutes <= escalation_minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_review_sla_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 48)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('warning_minutes')->default(24 * 60);
            $table->unsignedInteger('overdue_minutes')->default(2 * 24 * 60);
            $table->unsignedInteger('escalation_minutes')->default(3 * 24 * 60);
            // Audit: who last edited the thresholds, and the separate who/when of
            // the enable/disable toggle (the higher-impact governance action).
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('enabled_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_review_sla_policies');
    }
};
