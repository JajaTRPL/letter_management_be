<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->index('assigned_to', 'scholarship_applications_assigned_to_index');
        });

        Schema::table('surat_pengantar_magang_applications', function (Blueprint $table) {
            $table->index('assigned_to', 'spm_applications_assigned_to_index');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->dropIndex('scholarship_applications_assigned_to_index');
        });

        Schema::table('surat_pengantar_magang_applications', function (Blueprint $table) {
            $table->dropIndex('spm_applications_assigned_to_index');
        });
    }
};
