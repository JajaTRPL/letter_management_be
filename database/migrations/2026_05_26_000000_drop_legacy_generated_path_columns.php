<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pretend mode renders DDL without executing schema/data lookups.
        // A real migration execution always runs this fail-closed preflight.
        if (! DB::connection()->pretending()) {
            $this->assertTargetsAreSafeToDrop();
        }

        foreach ($this->targets() as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        /*
         * Rollback restores nullable schema shape only. Original
         * generated_docx_path values were exported in Global.2E1 and are
         * not automatically restored by this migration.
         */
        foreach ($this->targets() as $table => $columns) {
            $this->assertTableExists($table);

            $missingColumns = array_values(array_filter(
                $columns,
                fn (string $column): bool => ! Schema::hasColumn($table, $column)
            ));

            if ($missingColumns === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($missingColumns): void {
                foreach ($missingColumns as $column) {
                    $blueprint->string($column)->nullable();
                }
            });
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function targets(): array
    {
        return [
            'scholarship_applications' => [
                'generated_docx_path',
                'generated_pdf_path',
            ],
            'surat_keterangan_aktif_applications' => [
                'generated_pdf_path',
            ],
            'proses_luar_negeri_applications' => [
                'generated_pdf_path',
            ],
            'surat_pengantar_magang_applications' => [
                'generated_pdf_path',
            ],
            'letter_applications' => [
                'generated_docx_path',
            ],
        ];
    }

    private function assertTargetsAreSafeToDrop(): void
    {
        foreach ($this->targets() as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTableHasColumn($table, $column);
                $this->assertColumnHasNoNonNullValues($table, $column);
            }
        }
    }

    private function assertTableExists(string $table): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Cannot migrate legacy generated paths: required table [{$table}] does not exist.");
        }
    }

    private function assertTableHasColumn(string $table, string $column): void
    {
        $this->assertTableExists($table);

        if (! Schema::hasColumn($table, $column)) {
            throw new RuntimeException("Cannot migrate legacy generated paths: required column [{$table}.{$column}] does not exist.");
        }
    }

    private function assertColumnHasNoNonNullValues(string $table, string $column): void
    {
        $nonNullCount = DB::table($table)
            ->whereNotNull($column)
            ->count();

        if ($nonNullCount > 0) {
            throw new RuntimeException(
                "Cannot drop legacy generated path column [{$table}.{$column}]: found {$nonNullCount} non-null value(s)."
            );
        }
    }
};
