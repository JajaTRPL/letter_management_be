<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'surat_pengantar_magang_applications',
        'surat_keterangan_aktif_applications',
        'proses_luar_negeri_applications',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'kaprodi_approved_by')) {
                    $table->foreignId('kaprodi_approved_by')
                        ->nullable()
                        ->after('kaprodi_approved_at')
                        ->constrained('users')
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn($tableName, 'kadep_approved_by')) {
                    $table->foreignId('kadep_approved_by')
                        ->nullable()
                        ->after('kadep_approved_at')
                        ->constrained('users')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'kaprodi_approved_by')) {
                    $table->dropConstrainedForeignId('kaprodi_approved_by');
                }

                if (Schema::hasColumn($tableName, 'kadep_approved_by')) {
                    $table->dropConstrainedForeignId('kadep_approved_by');
                }
            });
        }
    }
};
