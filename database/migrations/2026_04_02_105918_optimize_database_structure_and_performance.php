<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop redundant table
        Schema::dropIfExists('scholarship_siblings');

        // 2. Add performance indexes
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'status')) {
                $table->index('status');
            }
        });

        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->index('status');
            $table->index('submitted_at');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['submitted_at']);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['created_at']);
        });

        // We don't recreate scholarship_siblings in the reverse because it was already redundant.
        // But for strict compatibility:
        Schema::create('scholarship_siblings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('job_or_school');
            $table->enum('marital_status', ['Belum Kawin', 'Kawin']);
            $table->enum('relation', ['Kakak', 'Adik']);
            $table->timestamps();
        });
    }
};
