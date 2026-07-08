<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tata tertib penggunaan ruangan, shown on the mahasiswa room detail.
     * Additive and nullable: existing rooms remain valid without rules.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->text('rules')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('rules');
        });
    }
};
