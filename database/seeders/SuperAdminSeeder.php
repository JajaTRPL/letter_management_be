<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Enums\PasswordSetMethod;
use App\Models\User;
use App\Services\PasswordCredentialService;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::create(array_merge([
            'name' => 'Super Admin',
            'email' => 'superadmin@mail.com',
            'role' => 'super_admin',
            'role_level' => 'primary',
        ], app(PasswordCredentialService::class)->attributes(
            'password123',
            PasswordSetMethod::SystemSeed,
        )));
    }
}
