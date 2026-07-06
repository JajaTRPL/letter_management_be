<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit/history tables for SuperAdmin verified-Mahasiswa imports.
     *
     * import_batches      — one record per uploaded file (dry-run creates it,
     *                       confirm completes it). Batch metadata is long-lived.
     * import_batch_rows   — row-level plan/outcome + errors, PII-minimized
     *                       (no tanggal_lahir); subject to retention via
     *                       import_batches.expires_at.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind')->default('verified_mahasiswa');
            $table->string('template_version', 10);
            $table->string('source_format', 10); // csv | xlsx
            $table->string('original_filename');
            $table->string('file_hash', 64);
            // nullOnDelete: users are hard-deleted by SuperAdmin flows; batch
            // history must never block that.
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('validated'); // validated | completed | failed | cancelled
            $table->boolean('override_existing_active')->default(false);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Row-level data retention marker; scheduled purge reads this.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('import_batch_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('email')->nullable();
            $table->string('nim')->nullable();
            $table->string('display_name')->nullable();
            $table->string('action', 10);  // create | update | skip | fail
            $table->string('status', 10);  // valid | invalid | imported | skipped | failed
            $table->json('errors_json')->nullable();
            $table->json('changes_json')->nullable();
            $table->timestamps();
            $table->index(['import_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_rows');
        Schema::dropIfExists('import_batches');
    }
};
