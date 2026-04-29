<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('mahasiswa_profile_id')->constrained('mahasiswa_profiles')->onDelete('cascade');
            $table->string('type'); // 'aktif', 'magang', 'luar_negeri'
            
            // General fields
            $table->string('tujuan_surat')->nullable();
            $table->text('keperluan')->nullable();

            // Additional fields for Aktif Letter (often required for BPJS/Bank)
            $table->string('pob')->nullable(); // Place of birth
            $table->date('dob')->nullable();  // Date of birth
            $table->string('gender')->nullable();
            
            // Parent info
            $table->string('parent_name')->nullable();
            $table->string('parent_job')->nullable();
            $table->string('parent_nip')->nullable();
            $table->string('parent_rank')->nullable();
            $table->string('parent_institution')->nullable();
            
            // Metadata
            $table->string('status')->default('Draft');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('generated_docx_path')->nullable();
            
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_applications');
    }
};
