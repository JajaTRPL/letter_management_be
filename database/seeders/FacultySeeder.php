<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Faculty;
use Illuminate\Support\Facades\DB;

class FacultySeeder extends Seeder
{
    public function run(): void
    {
        // Create faculties
        $faculties = [
            ['name' => 'Sekolah Vokasi', 'code' => 'SV'],
            ['name' => 'Fakultas Teknik', 'code' => 'FT'],
            ['name' => 'Fakultas MIPA', 'code' => 'FMIPA'],
        ];

        foreach ($faculties as $faculty) {
            Faculty::firstOrCreate(
                ['code' => $faculty['code']],
                ['name' => $faculty['name']]
            );
        }

        // Map all existing departments to Sekolah Vokasi (SV)
        $sv = Faculty::where('code', 'SV')->first();

        if ($sv) {
            // All current departments belong to Sekolah Vokasi
            $svDepartments = [
                'DTEDI',
                'DTM',
                'DTS',
                'DTK',
                'DLIKES',
                'DTHV',
                'DEB',
                'DBSMB',
            ];

            DB::table('departments')
                ->whereIn('code', $svDepartments)
                ->update(['faculty_id' => $sv->id]);
        }
    }
}
