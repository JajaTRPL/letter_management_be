<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Plain array → worksheet, used by the import template sheets and the
 * import error report. Caller is responsible for passing rows that are
 * already formula-escaped (SpreadsheetSafety) where values are untrusted.
 */
class ArraySheetExport implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    /**
     * @param list<array<int, mixed>> $rows
     * @param array<string, int>      $columnWidths
     */
    public function __construct(
        private string $title,
        private array $rows,
        private array $columnWidths = [],
        private bool $freezeHeader = false,
        private bool $boldFirstRow = false,
    ) {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function columnWidths(): array
    {
        return $this->columnWidths;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                if ($this->boldFirstRow && $this->rows !== []) {
                    $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->rows[0])));
                    $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
                }

                if ($this->freezeHeader) {
                    $sheet->freezePane('A2');
                }
            },
        ];
    }
}
