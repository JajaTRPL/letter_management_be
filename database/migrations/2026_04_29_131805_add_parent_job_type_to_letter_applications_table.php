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
        Schema::table('letter_applications', function (Blueprint $table) {
            $table->string('parent_job_type')->nullable()->after('parent_job');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('letter_applications', function (Blueprint $table) {
            $table->dropColumn('parent_job_type');
        });
    }
};
