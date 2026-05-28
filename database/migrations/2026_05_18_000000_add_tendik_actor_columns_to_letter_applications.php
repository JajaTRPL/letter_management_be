<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Tendik-stage actor audit columns to all four admin-letter tables.
 *
 * Today only Kaprodi/Kadep approvers are recorded by FK; the Tendik phase
 * stores `tendik_approved_at` (timestamp) but not who did it, and there is no
 * record at all of who pressed Revisi or Tolak. Without these columns, the
 * planned scope=team UI cannot honestly show "Diverifikasi/Direvisi/Ditolak oleh".
 *
 * All columns are nullable; existing rows stay null (no backfill).
 */
return new class extends Migration
{
    private array $tables = [
        'scholarship_applications',
        'surat_pengantar_magang_applications',
        'surat_keterangan_aktif_applications',
        'proses_luar_negeri_applications',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'tendik_approved_by')) {
                    $table->foreignId('tendik_approved_by')
                        ->nullable()
                        ->after('tendik_approved_at')
                        ->constrained('users')
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn($tableName, 'revised_by')) {
                    $table->foreignId('revised_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn($tableName, 'revised_at')) {
                    $table->timestamp('revised_at')->nullable();
                }

                if (!Schema::hasColumn($tableName, 'rejected_by')) {
                    $table->foreignId('rejected_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn($tableName, 'rejected_at')) {
                    $table->timestamp('rejected_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['tendik_approved_by', 'revised_by', 'rejected_by'] as $fkColumn) {
                    if (Schema::hasColumn($tableName, $fkColumn)) {
                        try {
                            $table->dropForeign([$fkColumn]);
                        } catch (\Throwable $e) {
                            // FK already gone — ignore.
                        }
                    }
                }

                $columnsToDrop = array_filter(
                    ['tendik_approved_by', 'revised_by', 'revised_at', 'rejected_by', 'rejected_at'],
                    fn (string $column) => Schema::hasColumn($tableName, $column),
                );

                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
};
