<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_application_attachments', function (Blueprint $table): void {
            $table->timestamp('retention_deleted_at')->nullable()->after('uploaded_by');
            $table->string('retention_status', 32)->nullable()->after('retention_deleted_at');
            $table->string('retention_manifest_path')->nullable()->after('retention_status');
            $table->index(['letter_type', 'application_id', 'retention_deleted_at'], 'laa_retention_lookup_idx');
        });

        Schema::table('letter_document_artifacts', function (Blueprint $table): void {
            $table->timestamp('retention_deleted_at')->nullable()->after('generated_at');
            $table->string('retention_status', 32)->nullable()->after('retention_deleted_at');
            $table->string('retention_manifest_path')->nullable()->after('retention_status');
            $table->string('archive_disk', 32)->nullable()->after('retention_manifest_path');
            $table->string('archive_path')->nullable()->after('archive_disk');
            $table->char('archive_checksum_sha256', 64)->nullable()->after('archive_path');
            $table->timestamp('archived_at')->nullable()->after('archive_checksum_sha256');
            $table->timestamp('archive_purged_at')->nullable()->after('archived_at');
            $table->index(['letter_type', 'application_id', 'phase', 'archived_at'], 'lda_retention_archive_idx');
            $table->index(['letter_type', 'application_id', 'retention_deleted_at'], 'lda_retention_delete_idx');
        });

        Schema::create('letter_retention_actions', function (Blueprint $table): void {
            $table->id();
            $table->char('action_key', 64)->unique();
            $table->string('letter_type', 64);
            $table->unsignedBigInteger('application_id');
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('category', 32);
            $table->string('action', 32);
            $table->string('status', 32);
            $table->string('storage_disk', 32)->nullable();
            $table->char('storage_path_hash', 64)->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('manifest_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['letter_type', 'application_id', 'category'], 'lra_letter_category_idx');
            $table->index(['category', 'action', 'status'], 'lra_action_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_retention_actions');

        Schema::table('letter_document_artifacts', function (Blueprint $table): void {
            $table->dropIndex('lda_retention_archive_idx');
            $table->dropIndex('lda_retention_delete_idx');
            $table->dropColumn([
                'retention_deleted_at',
                'retention_status',
                'retention_manifest_path',
                'archive_disk',
                'archive_path',
                'archive_checksum_sha256',
                'archived_at',
                'archive_purged_at',
            ]);
        });

        Schema::table('letter_application_attachments', function (Blueprint $table): void {
            $table->dropIndex('laa_retention_lookup_idx');
            $table->dropColumn([
                'retention_deleted_at',
                'retention_status',
                'retention_manifest_path',
            ]);
        });
    }
};
