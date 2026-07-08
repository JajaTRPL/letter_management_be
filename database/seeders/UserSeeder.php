<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Enums\PasswordSetMethod;
use App\Models\User;
use App\Services\PasswordCredentialService;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $passwordCredentials = app(PasswordCredentialService::class);

        User::create([
            'name' => 'Mahasiswa',
            'email' => 'mahasiswa@mail.com',
            'password' => null,
            'role' => 'mahasiswa',
        ]);

        User::create(array_merge([
            'name' => 'Admin Tendik',
            'email' => 'tendik@mail.com',
            'role' => 'tendik',
        ], $passwordCredentials->attributes(
            'password123',
            PasswordSetMethod::SystemSeed,
        )));
    }
}
