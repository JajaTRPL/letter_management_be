<?php

namespace Tests\Feature\Auth;

use App\Enums\PasswordSetMethod;
use App\Enums\UserStatus;
use App\Models\MahasiswaProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentPasswordInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_dry_run_classifies_students_masks_pii_ignores_other_roles_and_mutates_nothing(): void
    {
        $fixtures = $this->seedInventoryFixtures();
        $beforeUsers = DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $beforeProfiles = DB::table('mahasiswa_profiles')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $exitCode = Artisan::call('users:student-password-inventory', [
            '--dry-run' => true,
            '--environment-label' => 'testing',
            '--show-samples' => '10',
            '--include-google-linked' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('DRY-RUN_READ_ONLY', $report['mode']);
        $this->assertFalse($report['safety']['non_mahasiswa_scanned']);
        $this->assertArrayNotHasKey('policy_breakdown', $report);
        $this->assertSame([
            'total_mahasiswa' => 5,
            'password_null' => 1,
            'password_not_null' => 4,
            'google_linked_with_password' => 1,
            'google_linked_passwordless' => 0,
            'passwordless_without_google' => 1,
            'local_password_without_google' => 3,
            'local_password_current_identity_available' => 3,
            'local_password_missing_nim_or_birthdate' => 1,
            'current_pattern_match' => 2,
            'unknown_non_current_pattern' => 1,
            'unverifiable_hash_format' => 0,
            'suspended_or_inactive_with_local_password' => 1,
            'pending_or_incomplete_with_local_password' => 1,
            'password_with_no_metadata' => 1,
            'password_method_legacy_unknown' => 2,
            'password_method_reset_password_otp' => 1,
            'password_must_rotate' => 1,
        ], $report['counts']);
        $this->assertSame([
            UserStatus::Active->value => 2,
            UserStatus::PendingProfile->value => 1,
            UserStatus::Suspended->value => 1,
        ], $report['local_password_status_breakdown']);
        $this->assertSame([
            PasswordSetMethod::LegacyUnknown->value => 2,
            PasswordSetMethod::ResetPasswordOtp->value => 1,
            PasswordSetMethod::SelfServiceChange->value => 1,
        ], $report['password_method_breakdown']);
        $this->assertTrue($report['password_origin_metadata']['available']);
        $this->assertStringContainsString('not proof', $report['warning']);
        $this->assertCount(4, $report['samples']);

        $raw = Artisan::output();
        foreach ($fixtures['raw_pii'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $raw);
        }
        foreach ($fixtures['hashes'] as $hash) {
            $this->assertStringNotContainsString($hash, $raw);
        }
        $this->assertStringNotContainsString('staff.example@ugm.ac.id', $raw);
        foreach ($report['samples'] as $sample) {
            $this->assertArrayNotHasKey('password', $sample);
            $this->assertArrayNotHasKey('password_hash', $sample);
            $this->assertArrayHasKey('email_masked', $sample);
            $this->assertArrayHasKey('nim_masked', $sample);
            $this->assertArrayHasKey('password_set_method', $sample);
            $this->assertArrayHasKey('password_must_rotate', $sample);
        }

        $this->assertSame($beforeUsers, DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($beforeProfiles, DB::table('mahasiswa_profiles')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_current_pattern_sample_filter_and_private_export_remain_masked(): void
    {
        $fixtures = $this->seedInventoryFixtures();
        $path = 'reports/student-password-inventory/staging-dry-run.json';

        $this->artisan('users:student-password-inventory', [
            '--dry-run' => true,
            '--environment-label' => 'testing',
            '--show-samples' => '10',
            '--current-pattern-only' => true,
            '--include-google-linked' => true,
            '--export' => $path,
        ])
            ->expectsOutputToContain('MODE: DRY-RUN / DATABASE READ-ONLY')
            ->expectsOutputToContain('not proof')
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($path);
        $raw = Storage::disk('local')->get($path);
        $report = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(2, $report['samples']);
        foreach ($report['samples'] as $sample) {
            $this->assertSame('current_pattern_match', $sample['classification']);
        }
        foreach ($fixtures['raw_pii'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $raw);
        }
        foreach ($fixtures['hashes'] as $hash) {
            $this->assertStringNotContainsString($hash, $raw);
        }
    }

    public function test_policy_breakdown_reports_all_roles_campaign_preview_and_break_glass_without_mutation(): void
    {
        $fixture = $this->seedPolicyBreakdownFixtures();
        $beforeUsers = DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $beforeProfiles = DB::table('mahasiswa_profiles')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $exitCode = Artisan::call('users:student-password-inventory', [
            '--dry-run' => true,
            '--environment-label' => 'testing',
            '--policy-breakdown' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $raw = Artisan::output();
        $report = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $policy = $report['policy_breakdown'];

        $this->assertTrue($report['safety']['non_mahasiswa_scanned']);
        $this->assertSame('all_users_count_only', $policy['scope']);
        $this->assertTrue($policy['metadata_available']);
        $this->assertSame([
            'total_users' => 14,
            'password_null' => 1,
            'password_not_null' => 13,
            'metadata_null' => 1,
            'metadata_not_null' => 13,
            'password_methods' => [
                PasswordSetMethod::LegacyUnknown->value => 9,
                PasswordSetMethod::ResetPasswordOtp->value => 2,
                PasswordSetMethod::SuperAdminSet->value => 1,
                PasswordSetMethod::SelfServiceChange->value => 1,
                PasswordSetMethod::TemporaryAdmin->value => 0,
                PasswordSetMethod::SystemMigration->value => 0,
                PasswordSetMethod::SystemSeed->value => 0,
                'other' => 0,
            ],
            'password_must_rotate' => 2,
        ], $policy['overall']);

        $this->assertSame(
            ['akademik', 'mahasiswa', 'super_admin', 'tendik'],
            array_keys($policy['by_role'])
        );
        $this->assertSame(1, $policy['by_role']['mahasiswa']['password_methods']['legacy_unknown']);
        $this->assertSame(3, $policy['by_role']['tendik']['password_methods']['legacy_unknown']);
        $this->assertSame(3, $policy['by_role']['akademik']['password_methods']['legacy_unknown']);
        $this->assertSame(2, $policy['by_role']['super_admin']['password_methods']['legacy_unknown']);
        $this->assertSame(1, $policy['by_role']['tendik']['password_must_rotate']);
        $this->assertSame(1, $policy['by_role']['akademik']['password_must_rotate']);

        $this->assertSame(1, $policy['by_tendik_specialization']['persuratan']['legacy_unknown']);
        $this->assertSame(1, $policy['by_tendik_specialization']['kepala_lab']['legacy_unknown']);
        $this->assertSame(1, $policy['by_tendik_specialization']['unknown']['legacy_unknown']);
        $this->assertSame(1, $policy['by_akademik_subrole']['kaprodi']['legacy_unknown']);
        $this->assertSame(1, $policy['by_akademik_subrole']['kadep']['legacy_unknown']);
        $this->assertSame(1, $policy['by_akademik_subrole']['unknown']['legacy_unknown']);
        $this->assertSame(1, $policy['by_super_admin_type']['primary']['legacy_unknown']);
        $this->assertSame(1, $policy['by_super_admin_type']['secondary']['legacy_unknown']);

        $this->assertSame([
            'available' => true,
            'timestamp_source' => 'password_set_at',
            'total' => 2,
            'last_7_days' => 1,
            'last_30_days' => 2,
        ], $policy['recent_reset_password_otp']);

        $campaign = $policy['campaign_eligibility'];
        $this->assertSame(1, $campaign['mahasiswa_legacy_unknown']);
        $this->assertSame(6, $campaign['staff_legacy_unknown']);
        $this->assertSame(2, $campaign['super_admin_legacy_unknown']);
        $this->assertSame(2, $campaign['already_password_must_rotate']);
        $this->assertSame(9, $campaign['warning_campaign_eligible']);
        $this->assertSame(7, $campaign['future_forced_local_login_rotation_eligible']);
        $this->assertSame(2, $campaign['excluded_from_mass_action_super_admin_continuity']);

        $this->assertTrue($policy['break_glass']['all_super_admins_legacy_unknown']);
        $this->assertTrue($policy['break_glass']['continuity_anchor_required']);
        $this->assertSame(
            'Do not force-rotate/nullify all Super Admins at once.',
            $policy['break_glass']['warning']
        );
        $this->assertSame(
            [
                'affected_count' => 9,
                'continuity_excluded_count' => 2,
                'preview_selectable_count' => 7,
                'warning' => 'Do not force-rotate/nullify all Super Admins at once.',
            ],
            $policy['mass_action_preview']['targets']['all-legacy-unknown']
        );
        $this->assertFalse($policy['mass_action_preview']['mutation_performed']);

        foreach ($fixture['emails'] as $email) {
            $this->assertStringNotContainsString($email, $raw);
        }
        $this->assertStringNotContainsString($fixture['password_hash'], $raw);
        $this->assertStringNotContainsString('$2y$', $raw);
        $this->assertStringNotContainsString('$argon2', $raw);

        $this->assertSame($beforeUsers, DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($beforeProfiles, DB::table('mahasiswa_profiles')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_command_fails_closed_without_dry_run_for_unsafe_path_and_for_environment_mismatch(): void
    {
        $this->artisan('users:student-password-inventory', [
            '--environment-label' => 'testing',
        ])
            ->expectsOutputToContain('--dry-run is required')
            ->assertExitCode(1);

        $this->artisan('users:student-password-inventory', [
            '--dry-run' => true,
            '--environment-label' => 'testing',
            '--export' => '../inventory.json',
        ])
            ->expectsOutputToContain('must be a JSON path under')
            ->assertExitCode(1);

        $this->artisan('users:student-password-inventory', [
            '--dry-run' => true,
            '--environment-label' => 'production',
            '--authorized-read-only' => true,
        ])
            ->expectsOutputToContain('APP_ENV must match')
            ->assertExitCode(1);

        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    /**
     * @return array{raw_pii: list<string>, hashes: list<string>}
     */
    private function seedInventoryFixtures(): array
    {
        $rawPii = [];
        $hashes = [];

        $passwordless = User::factory()->create([
            'email' => 'passwordless.student@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'google_id' => null,
            'password' => null,
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create([
            'user_id' => $passwordless->id,
            'nim' => '24/100001/SV/10001',
            'tanggal_lahir' => '2004-01-01',
        ]);

        $googlePattern = '24100002SV1000202022004';
        $googleHash = Hash::make($googlePattern);
        $googleLinked = User::factory()->create([
            'email' => 'google.legacy@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'google_id' => 'google-legacy-2',
            'password' => $googleHash,
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create([
            'user_id' => $googleLinked->id,
            'nim' => '24/100002/SV/10002',
            'tanggal_lahir' => '2004-02-02',
        ]);

        $suspendedPattern = '24100003SV1000303032004';
        $suspendedHash = Hash::make($suspendedPattern);
        $suspended = User::factory()->create([
            'email' => 'suspended.legacy@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'google_id' => null,
            'password' => $suspendedHash,
            'password_set_method' => PasswordSetMethod::LegacyUnknown,
            'status' => UserStatus::Suspended,
        ]);
        MahasiswaProfile::create([
            'user_id' => $suspended->id,
            'nim' => '24/100003/SV/10003',
            'tanggal_lahir' => '2004-03-03',
        ]);

        $unknownHash = Hash::make('A-legitimate-looking-but-unknown-password1!');
        $unknown = User::factory()->create([
            'email' => 'unknown.origin@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'google_id' => null,
            'password' => $unknownHash,
            'password_set_method' => PasswordSetMethod::ResetPasswordOtp,
            'password_set_at' => now(),
            'status' => UserStatus::Active,
        ]);
        MahasiswaProfile::create([
            'user_id' => $unknown->id,
            'nim' => '24/100004/SV/10004',
            'tanggal_lahir' => '2004-04-04',
        ]);

        $missingHash = Hash::make('UnknownMissingIdentity1!');
        $missingIdentity = User::factory()->create([
            'email' => 'missing.identity@mail.ugm.ac.id',
            'role' => 'mahasiswa',
            'google_id' => null,
            'password' => $missingHash,
            'password_set_method' => PasswordSetMethod::SelfServiceChange,
            'password_set_at' => now(),
            'password_set_by_user_id' => null,
            'password_must_rotate' => true,
            'status' => UserStatus::PendingProfile,
        ]);
        MahasiswaProfile::create([
            'user_id' => $missingIdentity->id,
            'nim' => '24/100005/SV/10005',
            'tanggal_lahir' => null,
        ]);

        User::factory()->create([
            'email' => 'staff.example@ugm.ac.id',
            'role' => 'tendik',
            'password' => Hash::make('24100006SV1000606062004'),
        ]);

        foreach ([
            [$passwordless, '24/100001/SV/10001'],
            [$googleLinked, '24/100002/SV/10002'],
            [$suspended, '24/100003/SV/10003'],
            [$unknown, '24/100004/SV/10004'],
            [$missingIdentity, '24/100005/SV/10005'],
        ] as [$user, $nim]) {
            $rawPii[] = $user->email;
            $rawPii[] = $nim;
        }

        $hashes = [$googleHash, $suspendedHash, $unknownHash, $missingHash];

        return [
            'raw_pii' => $rawPii,
            'hashes' => $hashes,
        ];
    }

    /**
     * @return array{emails: list<string>, password_hash: string}
     */
    private function seedPolicyBreakdownFixtures(): array
    {
        $passwordHash = Hash::make('PolicyInventoryOnly1!');
        $emails = [];
        $create = function (array $attributes) use ($passwordHash, &$emails): User {
            $sequence = count($emails) + 1;
            $email = "policy.user{$sequence}@example.test";
            $emails[] = $email;

            return User::factory()->create(array_merge([
                'email' => $email,
                'password' => $passwordHash,
                'status' => UserStatus::Active,
                'password_set_method' => PasswordSetMethod::LegacyUnknown,
                'password_set_at' => null,
                'password_set_by_user_id' => null,
                'password_must_rotate' => false,
            ], $attributes));
        };

        $student = $create(['role' => 'mahasiswa']);
        MahasiswaProfile::create([
            'user_id' => $student->id,
            'nim' => '24/200001/SV/20001',
            'tanggal_lahir' => '2004-01-01',
        ]);
        $passwordlessStudent = $create([
            'role' => 'mahasiswa',
            'password' => null,
            'password_set_method' => null,
            'password_set_at' => null,
        ]);
        MahasiswaProfile::create([
            'user_id' => $passwordlessStudent->id,
            'nim' => '24/200002/SV/20002',
            'tanggal_lahir' => '2004-02-02',
        ]);

        $create(['role' => 'tendik', 'tendik_role' => 'persuratan']);
        $create([
            'role' => 'tendik',
            'tendik_role' => 'kepala_lab',
            'password_must_rotate' => true,
        ]);
        $create([
            'role' => 'tendik',
            'tendik_role' => 'sarpras',
            'password_set_method' => PasswordSetMethod::ResetPasswordOtp,
            'password_set_at' => now()->subDays(3),
        ]);
        $create([
            'role' => 'tendik',
            'tendik_role' => 'laboran',
            'password_set_method' => PasswordSetMethod::SelfServiceChange,
            'password_set_at' => now()->subDays(2),
        ]);
        $create(['role' => 'tendik', 'tendik_role' => null]);

        $create(['role' => 'akademik', 'sub_role' => 'kaprodi']);
        $create([
            'role' => 'akademik',
            'sub_role' => 'sekprodi',
            'password_set_method' => PasswordSetMethod::ResetPasswordOtp,
            'password_set_at' => now()->subDays(20),
        ]);
        $create([
            'role' => 'akademik',
            'sub_role' => 'kadep',
            'password_must_rotate' => true,
        ]);
        $create([
            'role' => 'akademik',
            'sub_role' => 'sekdep',
            'password_set_method' => PasswordSetMethod::SuperAdminSet,
            'password_set_at' => now()->subDays(40),
        ]);
        $create(['role' => 'akademik', 'sub_role' => null]);

        $create([
            'role' => 'super_admin',
            'role_level' => 'primary',
        ]);
        $create([
            'role' => 'super_admin',
            'role_level' => 'secondary',
        ]);

        return [
            'emails' => $emails,
            'password_hash' => $passwordHash,
        ];
    }
}
