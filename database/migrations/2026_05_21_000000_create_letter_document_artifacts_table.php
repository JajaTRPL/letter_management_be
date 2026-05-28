<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_document_artifacts', function (Blueprint $table) {
            $table->id();

            // Polymorphic association by letter_type + application_id. We deliberately
            // avoid Eloquent morphTo here because each letter has its own table
            // (scholarship_applications / surat_pengantar_magang_applications / ...) and
            // the existing codebase already addresses these by canonical letter_type
            // strings (see LetterTypeRegistry). Keeping the same convention.
            $table->string('letter_type', 64);
            $table->unsignedBigInteger('application_id');

            // Render phase (canonical: tendik_review | prodi_review | departemen_review | mahasiswa_review)
            $table->string('phase', 32);

            // Monotonic per (letter_type, application_id, phase). Newest = highest version.
            $table->unsignedInteger('version');

            // File locations on the public disk. docx_path is the filled source DOCX,
            // pdf_path is the rendered PDF. Both nullable to permit a row at status=generating
            // before either file is written.
            $table->string('docx_path')->nullable();
            $table->string('pdf_path')->nullable();

            // Deterministic sha256 of canonical render inputs (see LetterDocumentSourceHashService).
            $table->char('source_hash', 64);

            // generating | ready | failed
            // 'stale' may be introduced later if/when retention/cleanup needs it.
            $table->string('status', 16);

            $table->text('error_message')->nullable();

            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            // Latest-ready lookup + next-version calculation.
            $table->index(['letter_type', 'application_id', 'phase', 'version'], 'lda_app_phase_version_idx');

            // Status-filtered listing (e.g., latest READY of a phase).
            $table->index(['letter_type', 'application_id', 'phase', 'status', 'version'], 'lda_app_phase_status_version_idx');

            // Idempotency cache lookup: "does a ready artifact with this hash already exist?".
            // NOT a uniqueness constraint — retry-after-failure must be allowed, and the
            // service layer filters by status=ready when answering cache hits.
            $table->index(['letter_type', 'application_id', 'phase', 'source_hash'], 'lda_app_phase_hash_idx');

            $table->foreign('generated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_document_artifacts');
    }
};
