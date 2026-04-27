<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('study_program_id')->nullable()->after('academic_program')->constrained('study_programs')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('study_program_id')->constrained('departments')->nullOnDelete();
        });

        // Drop the old string-based academic_program column (replaced by FK)
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('academic_program');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['study_program_id']);
            $table->dropForeign(['department_id']);
            $table->dropColumn(['study_program_id', 'department_id']);
            $table->string('academic_program')->nullable()->after('sub_role');
        });
    }
};
