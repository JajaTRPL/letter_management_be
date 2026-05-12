<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('scholarship_applications', 'kaprodi_approved_by')) {
                $table->foreignId('kaprodi_approved_by')
                    ->nullable()
                    ->after('kaprodi_approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('scholarship_applications', 'kadep_approved_by')) {
                $table->foreignId('kadep_approved_by')
                    ->nullable()
                    ->after('kadep_approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            if (Schema::hasColumn('scholarship_applications', 'kaprodi_approved_by')) {
                $table->dropConstrainedForeignId('kaprodi_approved_by');
            }

            if (Schema::hasColumn('scholarship_applications', 'kadep_approved_by')) {
                $table->dropConstrainedForeignId('kadep_approved_by');
            }
        });
    }
};
