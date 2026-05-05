<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surat_keterangan_aktif_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mahasiswa_profile_id')->nullable()->constrained('mahasiswa_profiles')->nullOnDelete();

            $table->string('tempat_lahir')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('jenis_kelamin')->nullable();
            $table->text('keperluan')->nullable();
            $table->string('nama_orang_tua_wali')->nullable();
            $table->string('pekerjaan_orang_tua_wali')->nullable();
            $table->string('nip_orang_tua_wali')->nullable();
            $table->string('pangkat_gol_orang_tua_wali')->nullable();
            $table->string('instansi_orang_tua_wali')->nullable();

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
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surat_keterangan_aktif_applications');
    }
};
