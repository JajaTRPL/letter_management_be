<?php

namespace App\Exports;

use App\Models\StudyProgram;
use App\Services\MahasiswaImportService;
use App\Support\SpreadsheetSafety;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Official XLSX import template (v2) for verified Mahasiswa data.
 *
 * Sheets: Petunjuk (instructions), Data Mahasiswa (the sheet the importer
 * reads), Referensi Prodi (valid study_program_code values).
 */
class MahasiswaImportTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $programs = StudyProgram::runtimeVisible()
            ->with('department.faculty')
            ->orderBy('code')
            ->get();

        $petunjuk = [
            ['Template Impor Data Mahasiswa Terverifikasi (' . MahasiswaImportService::TEMPLATE_VERSION . ')'],
            [''],
            ['Petunjuk Pengisian:'],
            ['1. Isi data pada sheet "Data Mahasiswa". Jangan mengubah nama sheet atau nama kolom.'],
            ['2. Kolom wajib: name, email, nim, study_program_code. Kolom tanggal_lahir opsional.'],
            ['3. name : Nama lengkap mahasiswa sesuai data resmi kampus.'],
            ['4. email : Email UGM mahasiswa (@mail.ugm.ac.id atau @ugm.ac.id).'],
            ['5. nim : Format NIM UGM, contoh 24/535278/SV/12345.'],
            ['6. study_program_code : Kode program studi sesuai sheet "Referensi Prodi".'],
            ['7. tanggal_lahir : Format YYYY-MM-DD atau DD/MM/YYYY, contoh 2004-05-15.'],
            [''],
            ['Menggunakan Google Sheets?'],
            ['Pilih File → Download → Microsoft Excel (.xlsx) atau CSV (.csv), lalu unggah file tersebut di sistem.'],
            [''],
            ['Catatan:'],
            ['- Maksimal 5.000 baris data per impor.'],
            ['- File .xls (format Excel lama) tidak didukung.'],
            ['- Baris contoh memakai kode prodi khusus "' . MahasiswaImportService::SAMPLE_PROGRAM_CODE . '" sehingga tidak akan pernah ikut terimpor.'],
            ['- Hapus atau timpa baris contoh dengan data mahasiswa yang sebenarnya sebelum mengunggah.'],
        ];

        $data = [
            MahasiswaImportService::HEADERS,
            SpreadsheetSafety::escapeRow([
                'Contoh: Budi Santoso',
                'budi.contoh@mail.ugm.ac.id',
                '24/535278/SV/12345',
                MahasiswaImportService::SAMPLE_PROGRAM_CODE,
                '2004-05-15',
            ]),
        ];

        $referensi = [
            ['study_program_code', 'Nama Program Studi', 'Departemen', 'Fakultas'],
        ];
        foreach ($programs as $program) {
            $referensi[] = SpreadsheetSafety::escapeRow([
                $program->code,
                $program->name,
                $program->department?->name ?? '-',
                $program->department?->faculty?->name ?? '-',
            ]);
        }

        return [
            new ArraySheetExport('Petunjuk', $petunjuk, ['A' => 100]),
            new ArraySheetExport(
                MahasiswaImportService::DATA_SHEET,
                $data,
                ['A' => 28, 'B' => 34, 'C' => 24, 'D' => 22, 'E' => 16],
                freezeHeader: true,
                boldFirstRow: true,
            ),
            new ArraySheetExport(
                'Referensi Prodi',
                $referensi,
                ['A' => 22, 'B' => 42, 'C' => 36, 'D' => 32],
                freezeHeader: true,
                boldFirstRow: true,
            ),
        ];
    }
}
