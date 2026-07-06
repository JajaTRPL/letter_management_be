<?php

namespace App\Exports;

use App\Support\SpreadsheetSafety;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * XLSX user export with an appended "Info Ekspor" provenance sheet:
 * who exported, when, which filters, whether PII was included, and a
 * data-classification note. The data sheet stays first (active) so the
 * workbook opens on the data, not the metadata.
 */
class UsersExportBundle implements WithMultipleSheets
{
    public function __construct(
        private ?string $role,
        private bool $includePii,
        private string $generatedBy,
        private int $rowCount,
    ) {
    }

    public function sheets(): array
    {
        $dataSheets = $this->role
            ? [new UsersExport($this->role, $this->includePii)]
            : (new MultiUsersExport($this->includePii))->sheets();

        $info = new ArraySheetExport('Info Ekspor', [
            ['Informasi Ekspor'],
            [''],
            ['Dibuat pada', now()->format('Y-m-d H:i:s')],
            ['Dibuat oleh', SpreadsheetSafety::escapeCell($this->generatedBy)],
            ['Filter role', $this->role ?: 'semua'],
            ['Data pribadi (tanggal lahir)', $this->includePii ? 'disertakan' : 'tidak disertakan'],
            ['Jumlah baris data', (string) $this->rowCount],
            [''],
            ['Klasifikasi: data internal DTEDI.'],
            ['Gunakan hanya untuk kebutuhan administrasi yang sah dan jangan disebarluaskan.'],
        ], ['A' => 32, 'B' => 44], boldFirstRow: true);

        return [...$dataSheets, $info];
    }
}
