<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MARKER_SCHEME = 'attachment://';

    public function up(): void
    {
        // A real migration execution always runs the fail-closed preflight
        // before any schema mutation. Pretend mode renders DDL only.
        if (! DB::connection()->pretending()) {
            $this->assertSafeToDrop();
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
         * Rollback restores nullable column structure only. Historical values
         * are not restored by this migration; restoring values requires a DB
         * snapshot/archive taken before the guarded drop.
         */
        foreach ($this->targets() as $table => $columns) {
            $this->assertTableExists($table);

            $missingColumns = array_values(array_filter(
                $columns,
                fn (string $column): bool => ! Schema::hasColumn($table, $column),
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
                'transkrip_nilai_path',
                'slip_gaji_ayah_path',
                'slip_gaji_ibu_path',
                'ktm_path',
            ],
            'surat_pengantar_magang_applications' => [
                'proposal_kegiatan_magang_path',
            ],
            'surat_tugas_applications' => [
                'proposal_kegiatan_magang_path',
                'surat_pengantar_magang_path',
            ],
        ];
    }

    /**
     * @return list<array{table: string, column: string, letter_type: string, document_key: string}>
     */
    private function activeAttachmentColumns(): array
    {
        return [
            [
                'table' => 'scholarship_applications',
                'column' => 'transkrip_nilai_path',
                'letter_type' => 'surat-permohonan-beasiswa',
                'document_key' => 'transkrip_nilai',
            ],
            [
                'table' => 'scholarship_applications',
                'column' => 'slip_gaji_ayah_path',
                'letter_type' => 'surat-permohonan-beasiswa',
                'document_key' => 'slip_gaji_ayah',
            ],
            [
                'table' => 'scholarship_applications',
                'column' => 'slip_gaji_ibu_path',
                'letter_type' => 'surat-permohonan-beasiswa',
                'document_key' => 'slip_gaji_ibu',
            ],
            [
                'table' => 'surat_pengantar_magang_applications',
                'column' => 'proposal_kegiatan_magang_path',
                'letter_type' => 'surat-pengantar-magang',
                'document_key' => 'proposal',
            ],
            [
                'table' => 'surat_tugas_applications',
                'column' => 'proposal_kegiatan_magang_path',
                'letter_type' => 'surat-tugas',
                'document_key' => 'proposal',
            ],
            [
                'table' => 'surat_tugas_applications',
                'column' => 'surat_pengantar_magang_path',
                'letter_type' => 'surat-tugas',
                'document_key' => 'surat_pengantar_magang',
            ],
        ];
    }

    private function assertSafeToDrop(): void
    {
        foreach ($this->targets() as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTableHasColumn($table, $column);
                $this->assertColumnIsNullable($table, $column);
            }
        }

        $this->assertTableExists('letter_application_attachments');
        $this->assertNoMalformedValues();
        $this->assertActiveAttachmentRegistryCoverage();
        $this->assertDormantKtmHasNoHistoricalValues();
    }

    private function assertNoMalformedValues(): void
    {
        foreach ($this->targets() as $table => $columns) {
            foreach ($columns as $column) {
                $rows = DB::table($table)
                    ->select('id', $column)
                    ->whereNotNull($column)
                    ->where($column, '!=', '')
                    ->get();

                foreach ($rows as $row) {
                    $value = $row->{$column};
                    if (! is_string($value) || $this->normalizeLegacyValue($value) === null) {
                        throw new RuntimeException(
                            "Cannot drop legacy attachment column [{$table}.{$column}]: malformed value on application id [{$row->id}]."
                        );
                    }
                }
            }
        }
    }

    private function assertActiveAttachmentRegistryCoverage(): void
    {
        foreach ($this->activeAttachmentColumns() as $target) {
            $rows = DB::table($target['table'])
                ->select('id', $target['column'])
                ->whereNotNull($target['column'])
                ->where($target['column'], '!=', '')
                ->get();

            foreach ($rows as $row) {
                $value = (string) $row->{$target['column']};
                $hasRegistryRow = DB::table('letter_application_attachments')
                    ->where('letter_type', $target['letter_type'])
                    ->where('application_id', $row->id)
                    ->where('document_key', $target['document_key'])
                    ->exists();

                if (! $hasRegistryRow && str_starts_with($value, self::MARKER_SCHEME)) {
                    throw new RuntimeException(
                        "Cannot drop legacy attachment column [{$target['table']}.{$target['column']}]: marker-only value lacks registry coverage on application id [{$row->id}]."
                    );
                }

                if (! $hasRegistryRow) {
                    throw new RuntimeException(
                        "Cannot drop legacy attachment column [{$target['table']}.{$target['column']}]: legacy-only active attachment slot lacks registry coverage on application id [{$row->id}]."
                    );
                }
            }
        }
    }

    private function assertDormantKtmHasNoHistoricalValues(): void
    {
        $count = DB::table('scholarship_applications')
            ->whereNotNull('ktm_path')
            ->where('ktm_path', '!=', '')
            ->count();

        if ($count > 0) {
            throw new RuntimeException(
                "Cannot drop dormant legacy attachment column [scholarship_applications.ktm_path]: found {$count} historical value(s). Archive or clear explicitly before dropping."
            );
        }
    }

    private function assertTableExists(string $table): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Cannot drop legacy attachment columns: required table [{$table}] does not exist.");
        }
    }

    private function assertTableHasColumn(string $table, string $column): void
    {
        $this->assertTableExists($table);

        if (! Schema::hasColumn($table, $column)) {
            throw new RuntimeException("Cannot drop legacy attachment columns: required column [{$table}.{$column}] does not exist.");
        }
    }

    private function assertColumnIsNullable(string $table, string $column): void
    {
        $metadata = collect(Schema::getColumns($table))->firstWhere('name', $column);
        if (! is_array($metadata) || ($metadata['nullable'] ?? null) !== true) {
            throw new RuntimeException("Cannot drop legacy attachment column [{$table}.{$column}]: column is not nullable.");
        }
    }

    private function normalizeLegacyValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\0")) {
            return null;
        }

        if (str_starts_with($value, self::MARKER_SCHEME)) {
            return $value;
        }

        $decoded = $value;
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        $path = parse_url($decoded, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $decoded;
        $path = str_replace('\\', '/', trim($path, '/'));
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));

        if ($path === '' || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }

        foreach (['api/storage/', 'storage/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        foreach ($this->recognizedLegacyPrefixes() as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function recognizedLegacyPrefixes(): array
    {
        return [
            'scholarships/transcripts/',
            'scholarships/slips/',
            'letter-application-attachments/',
            'surat-pengantar-magang/proposals/',
            'surat-tugas/supporting/proposals/',
            'surat-tugas/supporting/pengantar/',
        ];
    }
};
