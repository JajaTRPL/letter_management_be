<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mahasiswa_profiles', function (Blueprint $table) {
            $table->string('import_batch_id')->nullable()->after('tanda_tangan_path');
            $table->string('data_source')->default('admin_create')->after('import_batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mahasiswa_profiles', function (Blueprint $table) {
            $table->dropColumn(['import_batch_id', 'data_source']);
        });
    }
};
