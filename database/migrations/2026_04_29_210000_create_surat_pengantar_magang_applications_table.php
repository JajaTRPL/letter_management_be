<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surat_pengantar_magang_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mahasiswa_profile_id')->nullable()->constrained('mahasiswa_profiles')->nullOnDelete();

            $table->string('nama_penerima')->nullable();
            $table->string('nama_perusahaan')->nullable();
            $table->text('alamat_perusahaan')->nullable();
            $table->string('peran')->nullable();
            $table->string('rentang_tanggal')->nullable();
            $table->string('dosen_pembimbing_dpa')->nullable();
            $table->string('proposal_kegiatan_magang_path')->nullable();

            $table->string('nomor_surat')->nullable();
            $table->string('generated_pdf_path')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('Draft');
            $table->text('revision_note')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('tendik_approved_at')->nullable();
            $table->timestamp('kaprodi_approved_at')->nullable();
            $table->timestamp('kadep_approved_at')->nullable();
            $table->timestamp('student_reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surat_pengantar_magang_applications');
    }
};
