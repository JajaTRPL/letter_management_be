<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MultiUsersExport implements WithMultipleSheets
{
    /**
     * @return array
     */
    public function sheets(): array
    {
        $roles = ['mahasiswa', 'tendik', 'akademik'];
        $sheets = [];

        foreach ($roles as $role) {
            $sheets[] = new UsersExport($role);
        }

        return $sheets;
    }
}
