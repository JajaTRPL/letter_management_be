<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_booking_requests', function (Blueprint $table) {
            $table->timestamp('review_started_at')->nullable()->after('submission_iteration');
            $table->foreignId('review_started_by')
                ->nullable()
                ->after('review_started_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('cancellation_source', 32)->nullable()->after('cancellation_reason');
            $table->string('cancelled_by_role_snapshot', 64)
                ->nullable()
                ->after('cancellation_source');

            $table->index(['status', 'review_started_at'], 'rbr_status_review_started_idx');
            $table->index('review_started_by', 'rbr_review_started_by_idx');
        });
    }

    public function down(): void
    {
        Schema::table('room_booking_requests', function (Blueprint $table) {
            $table->dropIndex('rbr_status_review_started_idx');
            $table->dropIndex('rbr_review_started_by_idx');
            $table->dropConstrainedForeignId('review_started_by');
            $table->dropColumn([
                'review_started_at',
                'cancellation_source',
                'cancelled_by_role_snapshot',
            ]);
        });
    }
};
