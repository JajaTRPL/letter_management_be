<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable, role-scoped, deduplicated in-app notification projection (C7N1).
 *
 * Deliberately a NEW table, not the generic Laravel `notifications` morph table
 * (which could not carry category / priority / subject / dedup / resolved state
 * safely). This became the single notification source of truth; the legacy morph
 * table and its scholarship notification classes were later retired (C7N4/C7N5).
 * Additive only — no existing table is altered and no historical backfill is done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();

            // A notification always belongs to exactly one user. The role
            // context is presentation/routing only — never an authorization
            // source; the deep-link target re-authorizes the current user.
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient_role', 32);
            $table->string('recipient_subrole', 32)->nullable();
            $table->unsignedBigInteger('recipient_scope_id')->nullable();

            $table->string('event_type', 64);
            $table->string('category', 24);   // action_required|reminder|update|system
            $table->string('priority', 12);   // urgent|high|normal|low

            $table->string('title');
            $table->string('body', 512);

            // Subject is referenced by TYPE + PUBLIC identifier only — never an
            // internal auto-increment id — so the FE deep-links through a
            // route key that re-authorizes, and no internal id leaks.
            $table->string('subject_type', 48)->nullable();
            $table->string('subject_public_id', 128)->nullable();

            // Allowlisted route KEY (resolved to a real deep link by the FE
            // registry), never a raw/absolute URL.
            $table->string('action_route_key', 64)->nullable();
            $table->string('action_label', 96)->nullable();

            // Stable per-(recipient, semantic-event) key. The unique index makes
            // repeated event/scheduler processing idempotent.
            $table->string('dedup_key', 191);
            $table->unsignedSmallInteger('schema_version')->default(1);

            $table->timestampTz('occurred_at');
            $table->timestampTz('read_at')->nullable();      // independent of resolved
            $table->timestampTz('resolved_at')->nullable();  // domain-controlled only
            $table->timestampTz('expires_at')->nullable();

            // Supersession chain — a newer notification may supersede an older
            // unresolved one while the history stays queryable.
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('app_notifications')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['recipient_user_id', 'dedup_key'], 'app_notifications_recipient_dedup_unique');
            // Inbox query: a recipient's unresolved/unread items, newest first.
            $table->index(['recipient_user_id', 'resolved_at', 'read_at', 'occurred_at'], 'app_notifications_inbox_idx');
            $table->index(['recipient_user_id', 'category'], 'app_notifications_recipient_category_idx');
            // Resolution sweeps by subject.
            $table->index(['subject_type', 'subject_public_id'], 'app_notifications_subject_idx');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
