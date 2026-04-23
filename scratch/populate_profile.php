<?php

use App\Models\MahasiswaProfile;

$p = MahasiswaProfile::where('user_id', 7)->first();

if ($p) {
    $p->update(['no_hp' => '081234567890']);
    $p->keluarga()->updateOrCreate(
        ['jenis_relasi' => 'ayah'],
        ['nama_lengkap' => 'Budi Santoso', 'pekerjaan' => 'Wiraswasta', 'penghasilan' => '3', 'status_hidup' => 'hidup']
    );
    $p->keluarga()->updateOrCreate(
        ['jenis_relasi' => 'ibu'],
        ['nama_lengkap' => 'Siti Aminah', 'pekerjaan' => 'Ibu Rumah Tangga', 'penghasilan' => '1', 'status_hidup' => 'hidup']
    );
    echo "Profile Updated Successfully for user 7\n";
} else {
    echo "Profile NOT FOUND for user 7\n";
}
