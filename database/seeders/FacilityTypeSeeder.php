<?php

namespace Database\Seeders;

use App\Models\FacilityType;
use Illuminate\Database\Seeder;

/**
 * Predefined room facility dictionary. Idempotent: keyed by slug, safe to
 * re-run; never touches manager-created custom types.
 */
class FacilityTypeSeeder extends Seeder
{
    public const PREDEFINED = [
        'proyektor' => 'Proyektor',
        'speaker' => 'Speaker',
        'mikrofon' => 'Mikrofon',
        'papan_tulis' => 'Papan tulis',
        'kursi' => 'Kursi',
        'meja' => 'Meja',
        'ac' => 'AC',
        'stop_kontak' => 'Stop kontak',
        'komputer' => 'Komputer',
        'internet' => 'Internet',
    ];

    public function run(): void
    {
        foreach (self::PREDEFINED as $slug => $name) {
            FacilityType::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'is_predefined' => true],
            );
        }
    }
}
