<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('faculty_id')
                  ->nullable()
                  ->after('name')
                  ->constrained('faculties')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['faculty_id']);
            $table->dropColumn('faculty_id');
        });
    }
};
