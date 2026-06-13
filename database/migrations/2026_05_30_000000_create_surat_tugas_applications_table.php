<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surat_tugas_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mahasiswa_profile_id')->nullable()->constrained('mahasiswa_profiles')->nullOnDelete();

            // Letter-specific data (Surat Tugas template contract).
            $table->string('nama_perusahaan')->nullable();
            $table->string('kegiatan')->nullable();
            $table->string('posisi')->nullable();
            $table->string('dosen_pembimbing_dpa')->nullable();
            $table->date('tgl_mulai')->nullable();
            $table->date('tgl_selesai')->nullable();

            // Supporting documents (private storage paths; S2 minimal = uploaded PDFs).
            $table->string('proposal_kegiatan_magang_path')->nullable();
            $table->string('surat_pengantar_magang_path')->nullable();

            // Final number entered manually by Tendik at approval.
            $table->string('nomor_surat_tugas')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('Draft');
            $table->text('revision_note')->nullable();
            $table->text('rejection_reason')->nullable();

            // Workflow actor + timestamp columns (mirrors canonical letters).
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('tendik_approved_at')->nullable();
            $table->foreignId('tendik_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('kaprodi_approved_at')->nullable();
            $table->foreignId('kaprodi_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('kadep_approved_at')->nullable();
            $table->foreignId('kadep_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revised_at')->nullable();
            $table->foreignId('revised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('student_reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surat_tugas_applications');
    }
};
