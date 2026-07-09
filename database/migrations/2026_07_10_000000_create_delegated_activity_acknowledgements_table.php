<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegated_activity_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->string('domain_type', 64);
            $table->string('subject_type', 128)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('idempotency_key', 160)->nullable();
            $table->foreignId('delegated_actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accountable_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accountable_role', 64);
            $table->string('represented_scope_type', 64)->nullable();
            $table->unsignedBigInteger('represented_scope_id')->nullable();
            $table->string('activity_type', 96);
            $table->text('activity_summary');
            $table->text('internal_note')->nullable();
            $table->text('student_facing_note')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('status', 32)->default('pending_review');
            $table->string('urgency', 32)->default('normal');
            $table->timestamp('performed_at');
            $table->timestamp('acknowledgement_due_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('acknowledgement_note')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('escalation_seen_by_superadmin_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'daa_idempotency_unique');
            $table->index('status', 'daa_status_idx');
            $table->index('urgency', 'daa_urgency_idx');
            $table->index('acknowledgement_due_at', 'daa_due_idx');
            $table->index(['accountable_user_id', 'status'], 'daa_accountable_status_idx');
            $table->index('delegated_actor_id', 'daa_actor_idx');
            $table->index(['represented_scope_type', 'represented_scope_id'], 'daa_scope_idx');
            $table->index(['domain_type', 'activity_type'], 'daa_domain_activity_idx');
            $table->index(['subject_type', 'subject_id'], 'daa_subject_idx');
            $table->index('performed_at', 'daa_performed_idx');
            $table->index('escalated_at', 'daa_escalated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegated_activity_acknowledgements');
    }
};
