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
        // 1. Create laboratories table
        Schema::create('laboratories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->timestamps();
        });

        // 2. Extend users table with tendik specialization + laboratory roles
        Schema::table('users', function (Blueprint $table) {
            $table->string('tendik_role')->nullable()->after('sub_role');   // persuratan | sarpras
            $table->string('laboratory_role')->nullable()->after('tendik_role'); // kepala_lab | laboran
            $table->foreignId('laboratory_id')->nullable()->after('laboratory_role')
                  ->constrained('laboratories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['laboratory_id']);
            $table->dropColumn(['tendik_role', 'laboratory_role', 'laboratory_id']);
        });

        Schema::dropIfExists('laboratories');
    }
};
