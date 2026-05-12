<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_periods', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 20)->index();
            $table->unsignedSmallInteger('year_start')->index();
            $table->string('semester_type', 20)->index();
            $table->unsignedTinyInteger('semester_order');
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();

            $table->index(['is_active', 'start_date', 'end_date'], 'academic_periods_active_dates_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_periods');
    }
};
