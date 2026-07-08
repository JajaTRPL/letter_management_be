<?php

namespace App\Exports;

use App\Enums\UserStatus;
use App\Helpers\NimHelper;
use App\Models\User;
use App\Support\SpreadsheetSafety;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Privacy-safe user export.
 *
 * - Never includes password, tokens, google_id, or security metadata.
 * - tanggal_lahir (PII) only when explicitly requested via include_pii.
 * - Every cell passes SpreadsheetSafety to block formula injection.
 * - FromQuery so XLSX generation reads in chunks; the CSV path streams
 *   the same query with a cursor.
 */
class UsersExport implements FromQuery, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        protected ?string $role = null,
        protected bool $includePii = false,
    ) {
    }

    public function query()
    {
        $query = User::query()->where('role', '!=', 'super_admin');

        if ($this->role) {
            $query->where('role', $this->role);
        }

        return $query->with([
            'mahasiswaProfile:id,user_id,nim,tanggal_lahir',
            'studyProgram:id,code,name,department_id',
            'studyProgram.department:id,code,name,faculty_id',
            'studyProgram.department.faculty:id,code,name',
        ])->orderBy('created_at');
    }

    public function headings(): array
    {
        $headings = [
            'Nama',
            'Email',
            'NIM',
            'Kode Prodi',
            'Program Studi',
            'Departemen',
            'Fakultas',
            'Angkatan',
            'Role',
            'Jabatan',
            'Status',
            'Dibuat Pada',
        ];

        if ($this->includePii) {
            array_splice($headings, 4, 0, ['Tanggal Lahir']);
        }

        return $headings;
    }

    public function map($user): array
    {
        $status = $user->status instanceof UserStatus ? $user->status->value : (string) $user->status;

        $row = [
            $user->name,
            $user->email,
            $user->mahasiswaProfile?->nim ?? '-',
            $user->studyProgram?->code ?? '-',
            $user->studyProgram?->name ?? '-',
            $user->studyProgram?->department?->name ?? '-',
            $user->studyProgram?->department?->faculty?->name ?? '-',
            NimHelper::deriveAngkatan($user->mahasiswaProfile?->nim),
            $user->role,
            $this->jabatan($user),
            $status,
            $user->created_at ? $user->created_at->format('Y-m-d H:i:s') : '-',
        ];

        if ($this->includePii) {
            array_splice($row, 4, 0, [$user->mahasiswaProfile?->tanggal_lahir ?? '-']);
        }

        return SpreadsheetSafety::escapeRow($row);
    }

    public function title(): string
    {
        return $this->role ? ucfirst($this->role) : 'Semua User';
    }

    private function jabatan(User $user): string
    {
        if ($user->role === 'akademik') {
            return $user->akademik_label ?? '-';
        }

        if ($user->role === 'tendik') {
            return $user->tendik_role ?? '-';
        }

        return '-';
    }
}
