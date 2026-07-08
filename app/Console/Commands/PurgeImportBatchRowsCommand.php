<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use Illuminate\Console\Command;

/**
 * Retention purge for row-level import data (names/emails/NIMs and error
 * payloads). Batch metadata — counts, uploader, file hash, status — is
 * deliberately preserved as the long-lived audit record.
 */
class PurgeImportBatchRowsCommand extends Command
{
    protected $signature = 'import-batches:purge {--dry-run : Tampilkan jumlah yang akan dihapus tanpa menghapus}';

    protected $description = 'Hapus baris impor (data level-baris) yang sudah melewati masa penyimpanan (expires_at)';

    public function handle(): int
    {
        $expiredBatchIds = ImportBatch::where('expires_at', '<=', now())->pluck('id');

        $rowQuery = ImportBatchRow::whereIn('import_batch_id', $expiredBatchIds);
        $rowCount = $rowQuery->count();

        if ($rowCount === 0) {
            $this->info('Tidak ada baris impor yang melewati masa penyimpanan.');

            return self::SUCCESS;
        }

        $batchCount = ImportBatchRow::whereIn('import_batch_id', $expiredBatchIds)
            ->distinct('import_batch_id')
            ->count('import_batch_id');

        if ($this->option('dry-run')) {
            $this->info("Dry-run: {$rowCount} baris dari {$batchCount} batch akan dihapus. Tidak ada data yang diubah.");

            return self::SUCCESS;
        }

        $deleted = $rowQuery->delete();

        $this->info("Selesai: {$deleted} baris impor dari {$batchCount} batch kedaluwarsa dihapus. Metadata batch dipertahankan untuk audit.");

        return self::SUCCESS;
    }
}
