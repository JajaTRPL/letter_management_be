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
            $table->string('scholarship_name')->nullable()->change();
            $table->integer('current_semester')->nullable()->change();
            $table->integer('family_dependents')->nullable()->change();
            $table->float('gpa_last_2_semesters')->nullable()->change();
            $table->float('ipk')->nullable()->change();
            $table->integer('sks_last_2_semesters')->nullable()->change();
            $table->integer('total_sks_passed')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->string('scholarship_name')->nullable(false)->change();
            $table->integer('current_semester')->nullable(false)->change();
            $table->integer('family_dependents')->nullable(false)->change();
            $table->float('gpa_last_2_semesters')->nullable(false)->change();
            $table->float('ipk')->nullable(false)->change();
            $table->integer('sks_last_2_semesters')->nullable(false)->change();
            $table->integer('total_sks_passed')->nullable(false)->change();
        });
    }
};
