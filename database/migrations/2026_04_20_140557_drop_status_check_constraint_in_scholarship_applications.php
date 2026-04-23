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
        // Drop the check constraint that was created by the original enum type in PostgreSQL
        DB::statement('ALTER TABLE scholarship_applications DROP CONSTRAINT IF EXISTS scholarship_applications_status_check');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // To reverse, we would normally re-add the constraint, but since we want it to stay a string, we leave it.
    }
};
