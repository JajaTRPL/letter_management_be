<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MultiUsersExport implements WithMultipleSheets
{
    public function __construct(
        protected bool $includePii = false,
    ) {
    }

    /**
     * @return array
     */
    public function sheets(): array
    {
        $roles = ['mahasiswa', 'tendik', 'akademik'];
        $sheets = [];

        foreach ($roles as $role) {
            $sheets[] = new UsersExport($role, $this->includePii);
        }

        return $sheets;
    }
}
