<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AcademicStructureSeeder::class,
            FacultySeeder::class,
            SuperAdminSeeder::class,
            AdminSeeder::class,
            UserSeeder::class,
        ]);
    }
}