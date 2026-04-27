<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\StudyProgram;

class AcademicStructureSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            'DTEDI' => 'Departemen Teknik Elektro dan Informatika',
            'DTM' => 'Departemen Teknik Mesin',
            'DTS' => 'Departemen Teknik Sipil',
            'DTK' => 'Departemen Teknologi Kebumian',
            'DLIKES' => 'Departemen Layanan dan Informasi Kesehatan',
            'DTHV' => 'Departemen Teknologi Hayati dan Veteriner',
            'DEB' => 'Departemen Ekonomika dan Bisnis',
            'DBSMB' => 'Departemen Bahasa, Seni, dan Manajemen Budaya',
        ];

        $programs = [
            'DTEDI' => [
                'TRPL' => 'Teknologi Rekayasa Perangkat Lunak',
                'TRI' => 'Teknologi Rekayasa Internet',
                'TRE' => 'Teknologi Rekayasa Elektro',
                'TRIK' => 'Teknologi Rekayasa Instrumentasi dan Kontrol',
            ],
            'DTM' => [
                'TRM' => 'Teknologi Rekayasa Mesin',
                'TPPAB' => 'Teknik Pengelolaan dan Perawatan Alat Berat',
            ],
            'DTS' => [
                'TPPIS' => 'Teknik Pengelolaan dan Pemeliharaan Infrastruktur Sipil',
                'TRPBS' => 'Teknologi Rekayasa Pelaksanaan Bangunan Sipil',
                'CIME' => 'Civil Infrastructure Management and Maintenance Engineering',
            ],
            'DTK' => [
                'SIG' => 'Sistem Informasi Geografis',
                'TSPD' => 'Teknologi Survei dan Pemetaan Dasar',
            ],
            'DLIKES' => [
                'RMIK' => 'Rekam Medis dan Informasi Kesehatan',
                'K3' => 'Keselamatan dan Kesehatan Kerja',
            ],
            'DTHV' => [
                'PH' => 'Pengelolaan Hutan',
                'TV' => 'Teknologi Veteriner',
                'PPA' => 'Pengembangan Produk Agroindustri',
            ],
            'DEB' => [
                'ASP' => 'Akuntansi Sektor Publik',
                'MPP' => 'Manajemen dan Penilaian Properti',
                'PEW' => 'Pembangunan Ekonomi Kewilayahan',
                'PBK' => 'Perbankan',
            ],
            'DBSMB' => [
                'BPW' => 'Bisnis Perjalanan Wisata',
                'PARI' => 'Pengelolaan Arsip dan Rekaman Informasi',
                'BING' => 'Bahasa Inggris (Terapan Kehumasan)',
                'BJEP' => 'Bahasa Jepang untuk Komunikasi Bisnis dan Profesional',
                'BMAN' => 'Bahasa Mandarin',
                'BKOR' => 'Bahasa Korea',
                'PAW' => 'Pengembangan Atraksi Wisata',
            ],
        ];

        foreach ($departments as $code => $name) {
            $dept = Department::firstOrCreate(
                ['code' => $code],
                ['name' => $name]
            );

            if (isset($programs[$code])) {
                foreach ($programs[$code] as $progCode => $progName) {
                    StudyProgram::firstOrCreate(
                        ['code' => $progCode],
                        ['name' => $progName, 'department_id' => $dept->id]
                    );
                }
            }
        }
    }
}
