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
            $table->string('transkrip_nilai_path')->nullable();
            $table->string('slip_gaji_ayah_path')->nullable();
            $table->string('slip_gaji_ibu_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->dropColumn([
                'transkrip_nilai_path',
                'slip_gaji_ayah_path',
                'slip_gaji_ibu_path'
            ]);
        });
    }
};
