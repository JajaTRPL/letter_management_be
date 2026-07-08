<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Enums\PasswordSetMethod;
use App\Models\User;
use App\Services\PasswordCredentialService;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::create(array_merge([
            'name' => 'Admin Akademik',
            'email' => 'akademik@mail.com',
            'role' => 'akademik',
        ], app(PasswordCredentialService::class)->attributes(
            'password123',
            PasswordSetMethod::SystemSeed,
        )));
    }
}
