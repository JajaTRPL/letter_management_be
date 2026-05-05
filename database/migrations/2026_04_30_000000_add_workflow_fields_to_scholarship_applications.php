<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('scholarship_applications', 'nomor_surat')) {
                $table->string('nomor_surat')->nullable()->after('generated_docx_path');
            }

            if (!Schema::hasColumn('scholarship_applications', 'generated_pdf_path')) {
                $table->string('generated_pdf_path')->nullable()->after('nomor_surat');
            }

            if (!Schema::hasColumn('scholarship_applications', 'revision_note')) {
                $table->text('revision_note')->nullable()->after('status');
            }

            if (!Schema::hasColumn('scholarship_applications', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('revision_note');
            }

            if (!Schema::hasColumn('scholarship_applications', 'student_reviewed_at')) {
                $table->timestamp('student_reviewed_at')->nullable()->after('kadep_approved_at');
            }

            if (!Schema::hasColumn('scholarship_applications', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('student_reviewed_at');
            }
        });
    }

    public function down(): void
    {
        $columns = array_filter([
            'nomor_surat',
            'generated_pdf_path',
            'revision_note',
            'rejection_reason',
            'student_reviewed_at',
            'completed_at',
        ], fn (string $column): bool => Schema::hasColumn('scholarship_applications', $column));

        if ($columns === []) {
            return;
        }

        Schema::table('scholarship_applications', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
