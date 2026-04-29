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
            $table->string('parent_group')->nullable()->after('parent_rank');
            $table->string('parent_employee_id')->nullable()->after('parent_job_type');
            $table->string('parent_position')->nullable()->after('parent_employee_id');
            $table->string('parent_npwp')->nullable()->after('parent_position');
            $table->string('parent_business_name')->nullable()->after('parent_npwp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('letter_applications', function (Blueprint $table) {
            $table->dropColumn([
                'parent_group',
                'parent_employee_id',
                'parent_position',
                'parent_npwp',
                'parent_business_name'
            ]);
        });
    }
};
