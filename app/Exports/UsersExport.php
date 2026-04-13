<?php

namespace App\Exports;

use App\Models\User;
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

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $query = User::where('role', '!=', 'super_admin');

        if ($this->role) {
            $query->where('role', $this->role);
        }

        return $query->with('mahasiswaProfile')->get();
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'ID',
            'Nama',
            'Email',
            'Role',
            'Sub Role',
            'Status',
            'NIM',
            'Fakultas',
            'Program Studi',
            'Tanggal Lahir',
            'Dibuat Pada'
        ];
    }

    /**
     * @param mixed $user
     * @return array
     */
    public function map($user): array
    {
        return [
            $user->id,
            $user->name,
            $user->email,
            $user->role,
            $user->sub_role ?? '-',
            $user->status,
            $user->mahasiswaProfile->nim ?? '-',
            $user->mahasiswaProfile->fakultas ?? '-',
            $user->mahasiswaProfile->program_studi ?? '-',
            $user->mahasiswaProfile->tanggal_lahir ?? '-',
            $user->created_at ? $user->created_at->format('Y-m-d H:i:s') : '-'
        ];
    }

    /**
     * @return string
     */
    public function title(): string
    {
        return $this->role ? ucfirst($this->role) : 'Semua User';
    }
}
