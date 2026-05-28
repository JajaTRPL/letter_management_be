<?php

namespace Tests\Feature\Workflow;

use App\Enums\UserStatus;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TendikProfileContractTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_profile_exposes_additive_fields_for_persuratan_with_assigned_tasks(): void
    {
        $tendik = $this->tendikPersuratan(
            [ScholarshipApplication::LETTER_TYPE, 'surat-keterangan-aktif'],
            ['name' => 'Tendik Persuratan', 'nip' => '199001012025011001']
        );

        $response = $this->actingAs($tendik, 'sanctum')
            ->getJson('/api/profile')
            ->assertOk();

        $response->assertJsonPath('user.role', 'tendik');
        $response->assertJsonPath('user.tendik_role', 'persuratan');
        $response->assertJsonPath('user.name', 'Tendik Persuratan');
        $response->assertJsonPath('user.nip', '199001012025011001');
        $response->assertJsonPath('user.id', $tendik->id);
        $response->assertJsonPath('user.status', UserStatus::Active->value);
        $response->assertJsonPath('user.study_program', null);
        $response->assertJsonPath('user.department', null);

        $assignedTasks = $response->json('user.assigned_tasks');
        $this->assertIsArray($assignedTasks);
        $this->assertContains(ScholarshipApplication::LETTER_TYPE, $assignedTasks);
        $this->assertContains('surat-keterangan-aktif', $assignedTasks);

        $this->assertNotNull($response->json('user.created_at'));
    }

    public function test_profile_returns_empty_assigned_tasks_for_non_persuratan_subroles(): void
    {
        foreach (['sarpras', 'kepala_lab', 'laboran'] as $subRole) {
            $tendik = User::create([
                'name' => "Tendik {$subRole}",
                'email' => $subRole . '-' . uniqid() . '@example.test',
                'password' => 'password',
                'role' => 'tendik',
                'tendik_role' => $subRole,
                'status' => UserStatus::Active,
                'assigned_tasks' => null,
            ]);

            $response = $this->actingAs($tendik, 'sanctum')
                ->getJson('/api/profile')
                ->assertOk();

            $response->assertJsonPath('user.role', 'tendik');
            $response->assertJsonPath('user.tendik_role', $subRole);
            $response->assertJsonPath('user.assigned_tasks', []);
            $response->assertJsonPath('user.id', $tendik->id);
            $this->assertNotNull($response->json('user.created_at'));
        }
    }

    public function test_akademik_profile_exposes_scope_data_for_all_academic_subroles(): void
    {
        $department = $this->department([
            'code' => 'DTEDI',
            'name' => 'Departemen Teknik Elektro dan Informatika',
        ])->load('faculty');
        $program = $this->studyProgram($department, [
            'code' => 'TRPL',
            'name' => 'Teknologi Rekayasa Perangkat Lunak',
        ]);

        foreach (['kaprodi', 'sekprodi'] as $subRole) {
            $akademik = $this->akademik($subRole, ['study_program_id' => $program->id]);

            $response = $this->actingAs($akademik, 'sanctum')
                ->getJson('/api/profile')
                ->assertOk();

            $response->assertJsonPath('user.role', 'akademik');
            $response->assertJsonPath('user.sub_role', $subRole);
            $response->assertJsonPath('user.study_program_id', $program->id);
            $response->assertJsonPath('user.study_program.code', 'TRPL');
            $response->assertJsonPath('user.study_program.name', 'Teknologi Rekayasa Perangkat Lunak');
            $response->assertJsonPath('user.study_program.department.code', 'DTEDI');
            $response->assertJsonPath('user.department_id', $department->id);
            $response->assertJsonPath('user.department.code', 'DTEDI');
            $response->assertJsonPath('user.faculty.id', $department->faculty->id);
        }

        foreach (['kadep', 'sekdep'] as $subRole) {
            $akademik = $this->akademik($subRole, ['department_id' => $department->id]);

            $response = $this->actingAs($akademik, 'sanctum')
                ->getJson('/api/profile')
                ->assertOk();

            $response->assertJsonPath('user.role', 'akademik');
            $response->assertJsonPath('user.sub_role', $subRole);
            $response->assertJsonPath('user.study_program', null);
            $response->assertJsonPath('user.study_program_id', null);
            $response->assertJsonPath('user.department_id', $department->id);
            $response->assertJsonPath('user.department.code', 'DTEDI');
            $response->assertJsonPath('user.department.name', 'Departemen Teknik Elektro dan Informatika');
            $response->assertJsonPath('user.faculty.id', $department->faculty->id);
        }
    }

    public function test_tendik_self_profile_cannot_change_email_via_api_profile(): void
    {
        $originalEmail = 'lock-' . uniqid() . '@example.test';
        $tendik = $this->tendikPersuratan([], [
            'name' => 'Original Name',
            'email' => $originalEmail,
        ]);

        $response = $this->actingAs($tendik, 'sanctum')->postJson('/api/profile', [
            'name' => 'Updated Name',
            'email' => 'attacker-' . uniqid() . '@example.test',
        ]);

        $response->assertOk();
        $tendik->refresh();

        // Name update should succeed.
        $this->assertSame('Updated Name', $tendik->name);
        // Email must be ignored at the self-profile endpoint.
        $this->assertSame($originalEmail, $tendik->email);
    }

    public function test_akademik_self_profile_also_ignores_email_changes(): void
    {
        // The non-mahasiswa branch is shared across Tendik / Akademik / Super Admin
        // self-profile. Email lockdown must hold for all of them.
        $originalEmail = 'akademik-' . uniqid() . '@example.test';
        $akademik = $this->akademik('kaprodi', [
            'name' => 'Original Akademik',
            'email' => $originalEmail,
        ]);

        $this->actingAs($akademik, 'sanctum')
            ->postJson('/api/profile', [
                'name' => 'Updated Akademik',
                'email' => 'attacker-' . uniqid() . '@example.test',
            ])
            ->assertOk();

        $akademik->refresh();
        $this->assertSame('Updated Akademik', $akademik->name);
        $this->assertSame($originalEmail, $akademik->email);
    }

    public function test_tendik_and_akademik_replacing_foto_profil_delete_old_user_photo(): void
    {
        Storage::fake('public');

        $users = [
            'tendik' => $this->tendikPersuratan([], [
                'photo_path' => Storage::url('profiles/fotos/tendik-old.jpg'),
            ]),
            'akademik' => $this->akademik('kaprodi', [
                'photo_path' => Storage::url('profiles/fotos/akademik-old.jpg'),
            ]),
        ];

        Storage::disk('public')->put('profiles/fotos/tendik-old.jpg', 'old-tendik-photo');
        Storage::disk('public')->put('profiles/fotos/akademik-old.jpg', 'old-akademik-photo');

        foreach ($users as $label => $user) {
            $response = $this->actingAs($user, 'sanctum')
                ->post('/api/profile', [
                    'pas_foto' => UploadedFile::fake()->image("{$label}-new.png", 200, 200)->size(64),
                ])
                ->assertOk();

            $newPath = $response->json('profile.pas_foto_path');
            $this->assertStringStartsWith('/storage/profiles/fotos/', $newPath);
            $this->assertSame($newPath, $user->fresh()->photo_path);
            Storage::disk('public')->assertExists($this->publicDiskPath($newPath));
            Storage::disk('public')->assertMissing("profiles/fotos/{$label}-old.jpg");
        }
    }

    public function test_tendik_and_akademik_replacing_ttd_delete_old_user_signature(): void
    {
        Storage::fake('public');

        $users = [
            'tendik' => $this->tendikPersuratan([], [
                'signature_path' => Storage::url('profiles/signatures/tendik-old.png'),
            ]),
            'akademik' => $this->akademik('sekdep', [
                'signature_path' => Storage::url('profiles/signatures/akademik-old.png'),
            ]),
        ];

        Storage::disk('public')->put('profiles/signatures/tendik-old.png', 'old-tendik-signature');
        Storage::disk('public')->put('profiles/signatures/akademik-old.png', 'old-akademik-signature');

        foreach ($users as $label => $user) {
            $response = $this->actingAs($user, 'sanctum')
                ->post('/api/profile', [
                    'tanda_tangan' => UploadedFile::fake()->image("{$label}-new.png", 200, 100)->size(64),
                ])
                ->assertOk();

            $newPath = $response->json('profile.tanda_tangan_path');
            $this->assertStringStartsWith('/storage/profiles/signatures/', $newPath);
            $this->assertSame($newPath, $user->fresh()->signature_path);
            Storage::disk('public')->assertExists($this->publicDiskPath($newPath));
            Storage::disk('public')->assertMissing("profiles/signatures/{$label}-old.png");
        }
    }

    public function test_non_mahasiswa_old_photo_and_signature_are_not_deleted_when_validation_fails(): void
    {
        Storage::fake('public');

        foreach ([
            'tendik' => $this->tendikPersuratan(),
            'akademik' => $this->akademik('kaprodi'),
        ] as $label => $user) {
            $photoPath = "profiles/fotos/{$label}-old.jpg";
            $signaturePath = "profiles/signatures/{$label}-old.png";
            Storage::disk('public')->put($photoPath, 'old-photo');
            Storage::disk('public')->put($signaturePath, 'old-signature');
            $user->forceFill([
                'photo_path' => Storage::url($photoPath),
                'signature_path' => Storage::url($signaturePath),
            ])->save();

            $this->actingAs($user, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->post('/api/profile', [
                    'pas_foto' => UploadedFile::fake()->image("{$label}-too-large.jpg", 200, 200)->size(2049),
                ])
                ->assertUnprocessable();

            $this->actingAs($user, 'sanctum')
                ->withHeaders(['Accept' => 'application/json'])
                ->post('/api/profile', [
                    'tanda_tangan' => UploadedFile::fake()->image("{$label}-too-large.png", 200, 100)->size(2049),
                ])
                ->assertUnprocessable();

            $user->refresh();
            $this->assertSame(Storage::url($photoPath), $user->photo_path);
            $this->assertSame(Storage::url($signaturePath), $user->signature_path);
            Storage::disk('public')->assertExists($photoPath);
            Storage::disk('public')->assertExists($signaturePath);
        }
    }

    public function test_self_profile_asset_path_request_fields_cannot_delete_another_users_files(): void
    {
        Storage::fake('public');

        $ownerPhoto = 'profiles/fotos/owner-photo.jpg';
        $ownerSignature = 'profiles/signatures/owner-signature.png';
        $actorPhoto = 'profiles/fotos/actor-old-photo.jpg';
        $actorSignature = 'profiles/signatures/actor-old-signature.png';

        foreach ([$ownerPhoto, $ownerSignature, $actorPhoto, $actorSignature] as $path) {
            Storage::disk('public')->put($path, 'asset');
        }

        $owner = $this->tendikPersuratan([], [
            'photo_path' => Storage::url($ownerPhoto),
            'signature_path' => Storage::url($ownerSignature),
        ]);
        $actor = $this->tendikPersuratan([], [
            'photo_path' => Storage::url($actorPhoto),
            'signature_path' => Storage::url($actorSignature),
        ]);

        $this->actingAs($actor, 'sanctum')
            ->post('/api/profile', [
                'photo_path' => Storage::url($ownerPhoto),
                'signature_path' => Storage::url($ownerSignature),
                'pas_foto' => UploadedFile::fake()->image('actor-new-photo.png', 200, 200)->size(64),
                'tanda_tangan' => UploadedFile::fake()->image('actor-new-signature.png', 200, 100)->size(64),
            ])
            ->assertOk();

        Storage::disk('public')->assertExists($ownerPhoto);
        Storage::disk('public')->assertExists($ownerSignature);
        Storage::disk('public')->assertMissing($actorPhoto);
        Storage::disk('public')->assertMissing($actorSignature);
        $this->assertSame(Storage::url($ownerPhoto), $owner->fresh()->photo_path);
        $this->assertSame(Storage::url($ownerSignature), $owner->fresh()->signature_path);
    }

    public function test_replacement_cleanup_skips_old_asset_still_referenced_by_another_user(): void
    {
        Storage::fake('public');

        $sharedPath = 'profiles/fotos/shared-photo.jpg';
        Storage::disk('public')->put($sharedPath, 'shared-photo');
        $owner = $this->tendikPersuratan([], [
            'photo_path' => Storage::url($sharedPath),
        ]);
        $actor = $this->tendikPersuratan([], [
            'photo_path' => Storage::url($sharedPath),
        ]);

        $this->actingAs($actor, 'sanctum')
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('actor-new-photo.png', 200, 200)->size(64),
            ])
            ->assertOk();

        Storage::disk('public')->assertExists($sharedPath);
        $this->assertSame(Storage::url($sharedPath), $owner->fresh()->photo_path);
        $this->assertNotSame(Storage::url($sharedPath), $actor->fresh()->photo_path);
    }

    public function test_mahasiswa_profile_response_remains_untouched_by_additive_fields(): void
    {
        [$student] = $this->completeMahasiswa();

        $payload = $this->actingAs($student, 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->json();

        // Mahasiswa branch must not gain the non-mahasiswa additive keys, only its own structure.
        $this->assertArrayHasKey('user', $payload);
        $this->assertArrayHasKey('profile', $payload);
        $this->assertArrayHasKey('normalized', $payload);
        $this->assertArrayHasKey('student', $payload);
        $this->assertArrayNotHasKey('tendik_role', $payload['user']);
        $this->assertArrayNotHasKey('assigned_tasks', $payload['user']);
    }

    public function test_tendik_can_set_nip_via_self_profile_when_empty(): void
    {
        $tendik = $this->tendikPersuratan([], ['nip' => null]);

        $this->actingAs($tendik, 'sanctum')
            ->postJson('/api/profile', [
                'nip' => '199001012025011001',
            ])
            ->assertOk()
            ->assertJsonPath('user.nip', '199001012025011001');

        $this->assertSame('199001012025011001', $tendik->fresh()->nip);
    }

    public function test_tendik_can_change_existing_nip_via_self_profile(): void
    {
        $tendik = $this->tendikPersuratan([], ['nip' => '198501012010011001']);

        $this->actingAs($tendik, 'sanctum')
            ->postJson('/api/profile', [
                'name' => 'Updated Tendik',
                'nip' => '199512122019011002',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Tendik')
            ->assertJsonPath('user.nip', '199512122019011002');

        $tendik->refresh();
        $this->assertSame('Updated Tendik', $tendik->name);
        $this->assertSame('199512122019011002', $tendik->nip);
    }

    public function test_akademik_can_update_name_via_self_profile(): void
    {
        $akademik = $this->akademik('kaprodi', ['name' => 'Original Kaprodi']);

        $this->actingAs($akademik, 'sanctum')
            ->postJson('/api/profile', [
                'name' => 'Updated Kaprodi',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Kaprodi');

        $this->assertSame('Updated Kaprodi', $akademik->fresh()->name);
    }

    public function test_akademik_can_set_nip_via_self_profile_when_empty(): void
    {
        $akademik = $this->akademik('kadep', ['nip' => null]);

        $this->actingAs($akademik, 'sanctum')
            ->postJson('/api/profile', [
                'nip' => '198001012006041001',
            ])
            ->assertOk()
            ->assertJsonPath('user.nip', '198001012006041001');

        $this->assertSame('198001012006041001', $akademik->fresh()->nip);
    }

    public function test_akademik_can_change_existing_nip_via_self_profile(): void
    {
        $akademik = $this->akademik('sekdep', ['nip' => '197001012000041001']);

        $this->actingAs($akademik, 'sanctum')
            ->postJson('/api/profile', [
                'nip' => '197512122005011002',
            ])
            ->assertOk()
            ->assertJsonPath('user.nip', '197512122005011002');

        $this->assertSame('197512122005011002', $akademik->fresh()->nip);
    }

    public function test_self_profile_rejects_nip_already_used_by_another_user(): void
    {
        $existing = $this->tendikPersuratan([], ['nip' => '199001012025011001']);
        $tendik = $this->tendikPersuratan([], ['nip' => null]);

        $this->actingAs($tendik, 'sanctum')
            ->postJson('/api/profile', [
                'nip' => '199001012025011001',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nip');

        $this->assertNull($tendik->fresh()->nip);
        $this->assertSame('199001012025011001', $existing->fresh()->nip);
    }

    public function test_self_profile_allows_keeping_own_nip_unchanged(): void
    {
        $tendik = $this->tendikPersuratan([], ['nip' => '199001012025011001']);

        $this->actingAs($tendik, 'sanctum')
            ->postJson('/api/profile', [
                'name' => 'Same NIP Update',
                'nip' => '199001012025011001',
            ])
            ->assertOk()
            ->assertJsonPath('user.nip', '199001012025011001');
    }

    public function test_self_profile_clears_nip_when_blank_string_submitted(): void
    {
        $tendik = $this->tendikPersuratan([], ['nip' => '199001012025011001']);

        $this->actingAs($tendik, 'sanctum')
            ->postJson('/api/profile', [
                'nip' => '   ',
            ])
            ->assertOk()
            ->assertJsonPath('user.nip', null);

        $this->assertNull($tendik->fresh()->nip);
    }

    public function test_self_profile_cannot_change_role_via_api_profile(): void
    {
        $tendik = $this->tendikPersuratan();

        $this->actingAs($tendik, 'sanctum')
            ->postJson('/api/profile', [
                'name' => 'Promote Attempt',
                'role' => 'super_admin',
            ])
            ->assertOk();

        $this->assertSame('tendik', $tendik->fresh()->role);
    }

    public function test_self_profile_cannot_change_status_via_api_profile(): void
    {
        $tendik = $this->tendikPersuratan();

        $this->actingAs($tendik, 'sanctum')
            ->postJson('/api/profile', [
                'status' => UserStatus::Suspended->value,
            ])
            ->assertOk();

        $this->assertSame(UserStatus::Active, $tendik->fresh()->status);
    }

    public function test_self_profile_cannot_change_tendik_role_via_api_profile(): void
    {
        $tendik = $this->tendikPersuratan();

        $this->actingAs($tendik, 'sanctum')
            ->postJson('/api/profile', [
                'tendik_role' => 'kepala_lab',
            ])
            ->assertOk();

        $this->assertSame('persuratan', $tendik->fresh()->tendik_role);
    }

    public function test_self_profile_cannot_change_assigned_tasks_via_api_profile(): void
    {
        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);

        $this->actingAs($tendik, 'sanctum')
            ->postJson('/api/profile', [
                'assigned_tasks' => ['surat-keterangan-aktif'],
            ])
            ->assertOk();

        $this->assertSame(
            [ScholarshipApplication::LETTER_TYPE],
            $tendik->fresh()->assigned_tasks
        );
    }

    public function test_self_profile_cannot_change_sub_role_via_api_profile(): void
    {
        $akademik = $this->akademik('kaprodi');
        $originalSubRole = $akademik->sub_role;

        $this->actingAs($akademik, 'sanctum')
            ->postJson('/api/profile', [
                'sub_role' => 'kadep',
            ])
            ->assertOk();

        $this->assertSame($originalSubRole, $akademik->fresh()->sub_role);
    }

    private function publicDiskPath(string $path): string
    {
        $path = ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/');

        return str_starts_with($path, 'storage/')
            ? substr($path, strlen('storage/'))
            : $path;
    }
}
