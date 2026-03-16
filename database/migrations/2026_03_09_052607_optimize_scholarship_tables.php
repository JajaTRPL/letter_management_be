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
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->json('siblings')->nullable()->after('ktm_path');
        });

        Schema::dropIfExists('scholarship_siblings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->dropColumn('siblings');
        });

        // Note: scholarship_siblings reconstruction would ideally be here if needed for rollback
    }
};
