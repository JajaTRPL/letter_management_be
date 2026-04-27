<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('scholarship_applications', 'kaprodi_approved_at')) {
                $table->timestamp('kaprodi_approved_at')->nullable();
            }
            if (!Schema::hasColumn('scholarship_applications', 'kadep_approved_at')) {
                $table->timestamp('kadep_approved_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->dropColumn(['kaprodi_approved_at', 'kadep_approved_at']);
        });
    }
};
