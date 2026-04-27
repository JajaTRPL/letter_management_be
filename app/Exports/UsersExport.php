<?php

namespace App\Exports;

use App\Models\User;
use App\Helpers\NimHelper;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class UsersExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    protected $role;

    public function __construct($role = null)
    {
        $this->role = $role;
    }

    public function collection()
    {
        $query = User::where('role', '!=', 'super_admin');

        if ($this->role) {
            $query->where('role', $this->role);
        }

        return $query->with([
            'mahasiswaProfile:id,user_id,nim,tanggal_lahir',
            'studyProgram:id,code,name,department_id',
            'studyProgram.department:id,code,name,faculty_id',
            'studyProgram.department.faculty:id,code,name',
        ])->get();
    }

    public function headings(): array
    {
        return [
            'Nama',
            'Email',
            'NIM',
            'Kode Prodi',
            'Tanggal Lahir',
            'Program Studi',
            'Departemen',
            'Fakultas',
            'Angkatan',
            'Role',
            'Status',
            'Dibuat Pada',
        ];
    }

    public function map($user): array
    {
        return [
            $user->name,
            $user->email,
            $user->mahasiswaProfile?->nim ?? '-',
            $user->studyProgram?->code ?? '-',
            $user->mahasiswaProfile?->tanggal_lahir ?? '-',
            $user->studyProgram?->name ?? '-',
            $user->studyProgram?->department?->name ?? '-',
            $user->studyProgram?->department?->faculty?->name ?? '-',
            NimHelper::deriveAngkatan($user->mahasiswaProfile?->nim),
            $user->role,
            $user->status,
            $user->created_at ? $user->created_at->format('Y-m-d H:i:s') : '-',
        ];
    }

    public function title(): string
    {
        return $this->role ? ucfirst($this->role) : 'Semua User';
    }
}
