<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Soft-availability for facility types. Inactive types are hidden from new
     * room assignments but preserved in existing room_facilities rows (no hard
     * delete — restrictOnDelete already blocks deleting a type in use).
     * Additive + defaulted true, so existing types stay available.
     */
    public function up(): void
    {
        Schema::table('facility_types', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_predefined');
        });
    }

    public function down(): void
    {
        Schema::table('facility_types', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
