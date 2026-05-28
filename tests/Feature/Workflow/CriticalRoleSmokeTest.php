<?php

namespace Tests\Feature\Workflow;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\MahasiswaProfile;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\StudyProgram;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CriticalRoleSmokeTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private const SMOKE_PASSWORD = 'password123';

    /**
     * @var string[]
     */
    private const SMOKE_EMAILS = [
        'smoke.tendik.beasiswa@example.test',
        'smoke.tendik.magang@example.test',
        'smoke.tendik.sarpras@example.test',
        'smoke.tendik.kepala_lab@example.test',
        'smoke.tendik.laboran@example.test',
        'smoke.kaprodi.trpl@example.test',
        'smoke.sekprodi.trpl@example.test',
        'smoke.kadep.dtedi@example.test',
        'smoke.sekdep.dtedi@example.test',
        'smoke.mahasiswa.scope@example.test',
        'superadmin.local@example.test',
    ];

    public function test_local_smoke_accounts_seed_idempotently_in_test_database_only(): void
    {
        $scope = $this->smokeAcademicScope();

        $this->seedLocalSmokeAccounts($scope['trpl'], $scope['dtedi']);
        $this->seedLocalSmokeAccounts($scope['trpl'], $scope['dtedi']);

        $this->assertSame(count(self::SMOKE_EMAILS), User::whereIn('email', self::SMOKE_EMAILS)->count());
        $this->assertSame(0, User::where('email', 'like', '%@ugm.ac.id')->count());

        foreach (self::SMOKE_EMAILS as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->assertStringEndsWith('@example.test', $user->email);
            $this->assertTrue(Hash::check(self::SMOKE_PASSWORD, $user->password), "{$email} does not use the smoke password.");
            $this->assertSame(UserStatus::Active, $user->status);
        }

        $this->assertSame('persuratan', User::where('email', 'smoke.tendik.beasiswa@example.test')->value('tendik_role'));
        $this->assertSame('kaprodi', User::where('email', 'smoke.kaprodi.trpl@example.test')->value('sub_role'));
        $this->assertSame('primary', User::where('email', 'superadmin.local@example.test')->value('role_level'));

        $student = User::where('email', 'smoke.mahasiswa.scope@example.test')->firstOrFail();
        $this->assertNotNull($student->mahasiswaProfile);
        $this->assertSame($scope['trpl']->id, $student->study_program_id);
    }

    public function test_tendik_smoke_scope_profile_and_forbidden_detail_contracts(): void
    {
        $scope = $this->smokeAcademicScope();
        $accounts = $this->seedLocalSmokeAccounts($scope['trpl'], $scope['dtedi']);

        $this->get('/api/tendik/dashboard/tasks')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->scholarshipApplication($accounts['student'], [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
            'assigned_to' => null,
        ]);
        $magang = $this->magangApplication($accounts['student'], [
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
            'assigned_to' => null,
        ]);
        $aktif = $this->aktifApplication($accounts['student'], [
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
            'assigned_to' => null,
        ]);
        $pln = $this->prosesLuarNegeriApplication($accounts['student'], [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'assigned_to' => null,
        ]);

        $profile = $this->actingAs($accounts['tendikBeasiswa'], 'sanctum')
            ->getJson('/api/profile')
            ->assertOk();
        $profile->assertJsonPath('user.email', 'smoke.tendik.beasiswa@example.test');
        $profile->assertJsonPath('user.assigned_tasks', [ScholarshipApplication::LETTER_TYPE]);

        $mine = $this->actingAs($accounts['tendikBeasiswa'], 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks')
            ->assertOk()
            ->json();

        $this->assertSame('mine', $mine['scope']);
        $this->assertContains(ScholarshipApplication::LETTER_TYPE, collect($mine['tasks'])->pluck('letter_type')->all());
        $this->assertNotContains(SuratPengantarMagangApplication::LETTER_TYPE, collect($mine['tasks'])->pluck('letter_type')->all());

        $teamRows = $this->actingAs($accounts['tendikBeasiswa'], 'sanctum')
            ->getJson('/api/tendik/dashboard/tasks?scope=team')
            ->assertOk()
            ->json('tasks');

        // Strict assignment: Tendik assigned only to Beasiswa must NOT see
        // other letter types in either scope. Team scope still drops the
        // assigned_to filter for letter types the user IS assigned to, but it
        // never exposes letter types absent from assigned_tasks.
        $this->assertTaskMissing($teamRows, SuratPengantarMagangApplication::LETTER_TYPE, $magang->id);
        $this->assertTaskMissing($teamRows, SuratKeteranganAktifApplication::LETTER_TYPE, $aktif->id);
        $this->assertTaskMissing($teamRows, ProsesLuarNegeriApplication::LETTER_TYPE, $pln->id);

        foreach (['tendikSarpras', 'tendikKepalaLab', 'tendikLaboran'] as $accountKey) {
            $this->actingAs($accounts[$accountKey], 'sanctum')
                ->getJson('/api/tendik/dashboard/tasks?scope=team')
                ->assertOk()
                ->assertJsonPath('tasks', [])
                ->assertJsonPath('stats.total_incoming', 0);

            $this->actingAs($accounts[$accountKey], 'sanctum')
                ->getJson('/api/tendik/riwayat?scope=team')
                ->assertOk()
                ->assertJsonPath('tasks', []);

            $response = $this->actingAs($accounts[$accountKey], 'sanctum')
                ->getJson("/api/tendik/surat-pengantar-magang/{$magang->id}")
                ->assertForbidden();
            $this->assertForbiddenDetailDoesNotLeak($response->json());
        }
    }

    public function test_akademik_smoke_scope_detail_action_and_riwayat_contracts(): void
    {
        Notification::fake();

        $scope = $this->smokeAcademicScope();
        $accounts = $this->seedLocalSmokeAccounts($scope['trpl'], $scope['dtedi']);
        $wrongKaprodi = $this->akademik('kaprodi', ['study_program_id' => $scope['tre']->id]);
        $wrongKadep = $this->akademik('kadep', ['department_id' => $scope['otherDepartment']->id]);

        [$treStudent] = $this->completeMahasiswa([], [], $scope['tre']);
        [$otherStudent] = $this->completeMahasiswa([], [], $scope['otherProgram']);

        $this->get('/api/akademik/dashboard/tasks')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        foreach ($this->letterCases() as $case) {
            $prodiApplication = $this->makeApplication($case, $accounts['student'], $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'));
            $wrongProdiApplication = $this->makeApplication($case, $treStudent, $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'));

            $prodiRows = $this->actingAs($accounts['kaprodiTrpl'], 'sanctum')
                ->getJson('/api/akademik/dashboard/tasks')
                ->assertOk()
                ->json('tasks');

            $this->assertTaskPresent($prodiRows, $case['letter_type'], $prodiApplication->id);
            $this->assertTaskMissing($prodiRows, $case['letter_type'], $wrongProdiApplication->id);

            $this->actingAs($accounts['sekprodiTrpl'], 'sanctum')
                ->getJson($this->showUrl($case, $prodiApplication))
                ->assertOk();

            $wrongProdiDetail = $this->actingAs($wrongKaprodi, 'sanctum')
                ->getJson($this->showUrl($case, $prodiApplication))
                ->assertForbidden();
            $this->assertForbiddenDetailDoesNotLeak($wrongProdiDetail->json());

            $this->actingAs($wrongKaprodi, 'sanctum')
                ->patchJson($this->actionUrl($case, $prodiApplication, 'approve'))
                ->assertForbidden();

            $departmentApplication = $this->makeApplication($case, $accounts['student'], $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'));
            $sameDepartmentApplication = $this->makeApplication($case, $treStudent, $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'));
            $wrongDepartmentApplication = $this->makeApplication($case, $otherStudent, $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'));

            $departmentRows = $this->actingAs($accounts['kadepDtedi'], 'sanctum')
                ->getJson('/api/akademik/dashboard/tasks')
                ->assertOk()
                ->json('tasks');

            $this->assertTaskPresent($departmentRows, $case['letter_type'], $departmentApplication->id);
            $this->assertTaskPresent($departmentRows, $case['letter_type'], $sameDepartmentApplication->id);
            $this->assertTaskMissing($departmentRows, $case['letter_type'], $wrongDepartmentApplication->id);

            $this->actingAs($accounts['sekdepDtedi'], 'sanctum')
                ->getJson($this->showUrl($case, $departmentApplication))
                ->assertOk();

            $wrongDepartmentDetail = $this->actingAs($wrongKadep, 'sanctum')
                ->getJson($this->showUrl($case, $departmentApplication))
                ->assertForbidden();
            $this->assertForbiddenDetailDoesNotLeak($wrongDepartmentDetail->json());

            $this->actingAs($wrongKadep, 'sanctum')
                ->patchJson($this->actionUrl($case, $departmentApplication, 'reject'), ['reason' => 'Tidak sesuai.'])
                ->assertForbidden();
        }

        $prodiHistory = $this->scholarshipApplication($accounts['student'], [
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'tendik_approved_at' => now()->subDay(),
            'kaprodi_approved_at' => now(),
        ]);
        $otherProdiHistory = $this->scholarshipApplication($treStudent, [
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'tendik_approved_at' => now()->subDay(),
            'kaprodi_approved_at' => now(),
        ]);

        $prodiHistoryRows = $this->actingAs($accounts['kaprodiTrpl'], 'sanctum')
            ->getJson('/api/akademik/riwayat')
            ->assertOk()
            ->json('tasks');
        $this->assertTaskPresent($prodiHistoryRows, ScholarshipApplication::LETTER_TYPE, $prodiHistory->id);
        $this->assertTaskMissing($prodiHistoryRows, ScholarshipApplication::LETTER_TYPE, $otherProdiHistory->id);

        $departmentHistory = $this->scholarshipApplication($accounts['student'], [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'tendik_approved_at' => now()->subDays(2),
            'kaprodi_approved_at' => now()->subDay(),
            'kadep_approved_at' => now(),
        ]);
        $otherDepartmentHistory = $this->scholarshipApplication($otherStudent, [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'tendik_approved_at' => now()->subDays(2),
            'kaprodi_approved_at' => now()->subDay(),
            'kadep_approved_at' => now(),
        ]);

        $departmentHistoryRows = $this->actingAs($accounts['kadepDtedi'], 'sanctum')
            ->getJson('/api/akademik/riwayat')
            ->assertOk()
            ->json('tasks');
        $this->assertTaskPresent($departmentHistoryRows, ScholarshipApplication::LETTER_TYPE, $departmentHistory->id);
        $this->assertTaskMissing($departmentHistoryRows, ScholarshipApplication::LETTER_TYPE, $otherDepartmentHistory->id);
    }

    public function test_beasiswa_smoke_prodi_to_department_transition_is_preserved(): void
    {
        Notification::fake();

        $scope = $this->smokeAcademicScope();
        $accounts = $this->seedLocalSmokeAccounts($scope['trpl'], $scope['dtedi']);
        $application = $this->scholarshipApplication($accounts['student'], [
            'status' => ScholarshipApplication::STATUS_SUBMITTED,
        ]);

        $this->mockBeasiswaPreviewGenerationForApprove();

        $this->actingAs($accounts['tendikBeasiswa'], 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve", [
                'nomor_surat' => 'SMOKE-BEA-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'SMOKE-BEA-001',
            'tendik_approved_by' => $accounts['tendikBeasiswa']->id,
        ]);

        $this->mockBeasiswaPreviewGenerationForProdiApprove();

        $this->actingAs($accounts['sekprodiTrpl'], 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'kaprodi_approved_by' => $accounts['sekprodiTrpl']->id,
        ]);

        $this->mockBeasiswaPreviewGenerationForDepartmentApprove();

        $this->actingAs($accounts['kadepDtedi'], 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'kadep_approved_by' => $accounts['kadepDtedi']->id,
        ]);
    }

    public function test_profile_self_edit_smoke_for_tendik_and_akademik(): void
    {
        $scope = $this->smokeAcademicScope();
        $accounts = $this->seedLocalSmokeAccounts($scope['trpl'], $scope['dtedi']);

        $this->actingAs($accounts['tendikBeasiswa'], 'sanctum')
            ->postJson('/api/profile', [
                'name' => 'Smoke Tendik Updated',
                'nip' => '199001012025011001',
                'email' => 'changed.tendik@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Smoke Tendik Updated')
            ->assertJsonPath('user.nip', '199001012025011001');

        $accounts['tendikBeasiswa']->refresh();
        $this->assertSame('Smoke Tendik Updated', $accounts['tendikBeasiswa']->name);
        $this->assertSame('199001012025011001', $accounts['tendikBeasiswa']->nip);
        $this->assertSame('smoke.tendik.beasiswa@example.test', $accounts['tendikBeasiswa']->email);

        $this->actingAs($accounts['kaprodiTrpl'], 'sanctum')
            ->postJson('/api/profile', [
                'nip' => '199001012025011001',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nip');

        $this->actingAs($accounts['kaprodiTrpl'], 'sanctum')
            ->postJson('/api/profile', [
                'name' => 'Smoke Kaprodi Updated',
                'nip' => '197512122005011002',
                'email' => 'changed.kaprodi@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Smoke Kaprodi Updated')
            ->assertJsonPath('user.nip', '197512122005011002');

        $accounts['kaprodiTrpl']->refresh();
        $this->assertSame('Smoke Kaprodi Updated', $accounts['kaprodiTrpl']->name);
        $this->assertSame('197512122005011002', $accounts['kaprodiTrpl']->nip);
        $this->assertSame('smoke.kaprodi.trpl@example.test', $accounts['kaprodiTrpl']->email);
    }

    /**
     * @return array{dtedi: Department, otherDepartment: Department, trpl: StudyProgram, tre: StudyProgram, otherProgram: StudyProgram}
     */
    private function smokeAcademicScope(): array
    {
        $dtedi = $this->department([
            'code' => 'DTEDI',
            'name' => 'Departemen Teknik Elektro dan Informatika',
        ]);
        $otherDepartment = $this->department([
            'code' => 'DLAIN',
            'name' => 'Departemen Lain',
        ]);

        return [
            'dtedi' => $dtedi,
            'otherDepartment' => $otherDepartment,
            'trpl' => $this->studyProgram($dtedi, [
                'code' => 'TRPL',
                'name' => 'Teknologi Rekayasa Perangkat Lunak',
            ]),
            'tre' => $this->studyProgram($dtedi, [
                'code' => 'TRE',
                'name' => 'Teknologi Rekayasa Elektro',
            ]),
            'otherProgram' => $this->studyProgram($otherDepartment, [
                'code' => 'TRO',
                'name' => 'Teknologi Rekayasa Lain',
            ]),
        ];
    }

    /**
     * @return array<string, User>
     */
    private function seedLocalSmokeAccounts(StudyProgram $trpl, Department $dtedi): array
    {
        $accounts = [
            'tendikBeasiswa' => $this->smokeUser('smoke.tendik.beasiswa@example.test', [
                'name' => 'Smoke Tendik Beasiswa',
                'role' => 'tendik',
                'tendik_role' => 'persuratan',
                'assigned_tasks' => [ScholarshipApplication::LETTER_TYPE],
            ]),
            'tendikMagang' => $this->smokeUser('smoke.tendik.magang@example.test', [
                'name' => 'Smoke Tendik Magang',
                'role' => 'tendik',
                'tendik_role' => 'persuratan',
                'assigned_tasks' => [SuratPengantarMagangApplication::LETTER_TYPE],
            ]),
            'tendikSarpras' => $this->smokeUser('smoke.tendik.sarpras@example.test', [
                'name' => 'Smoke Tendik Sarpras',
                'role' => 'tendik',
                'tendik_role' => 'sarpras',
                'assigned_tasks' => null,
            ]),
            'tendikKepalaLab' => $this->smokeUser('smoke.tendik.kepala_lab@example.test', [
                'name' => 'Smoke Tendik Kepala Lab',
                'role' => 'tendik',
                'tendik_role' => 'kepala_lab',
                'assigned_tasks' => null,
            ]),
            'tendikLaboran' => $this->smokeUser('smoke.tendik.laboran@example.test', [
                'name' => 'Smoke Tendik Laboran',
                'role' => 'tendik',
                'tendik_role' => 'laboran',
                'assigned_tasks' => null,
            ]),
            'kaprodiTrpl' => $this->smokeUser('smoke.kaprodi.trpl@example.test', [
                'name' => 'Smoke Kaprodi TRPL',
                'role' => 'akademik',
                'sub_role' => 'kaprodi',
                'study_program_id' => $trpl->id,
                'department_id' => $dtedi->id,
            ]),
            'sekprodiTrpl' => $this->smokeUser('smoke.sekprodi.trpl@example.test', [
                'name' => 'Smoke Sekprodi TRPL',
                'role' => 'akademik',
                'sub_role' => 'sekprodi',
                'study_program_id' => $trpl->id,
                'department_id' => $dtedi->id,
            ]),
            'kadepDtedi' => $this->smokeUser('smoke.kadep.dtedi@example.test', [
                'name' => 'Smoke Kadep DTEDI',
                'role' => 'akademik',
                'sub_role' => 'kadep',
                'study_program_id' => null,
                'department_id' => $dtedi->id,
            ]),
            'sekdepDtedi' => $this->smokeUser('smoke.sekdep.dtedi@example.test', [
                'name' => 'Smoke Sekdep DTEDI',
                'role' => 'akademik',
                'sub_role' => 'sekdep',
                'study_program_id' => null,
                'department_id' => $dtedi->id,
            ]),
            'student' => $this->smokeUser('smoke.mahasiswa.scope@example.test', [
                'name' => 'Smoke Mahasiswa Scope',
                'role' => 'mahasiswa',
                'study_program_id' => $trpl->id,
                'department_id' => null,
            ]),
            'superAdmin' => $this->smokeUser('superadmin.local@example.test', [
                'name' => 'Smoke Super Admin',
                'role' => 'super_admin',
                'role_level' => 'primary',
            ]),
        ];

        MahasiswaProfile::updateOrCreate(
            ['user_id' => $accounts['student']->id],
            [
                'nim' => '24999999',
                'fakultas' => 'Sekolah Vokasi',
                'program_studi' => $trpl->name,
                'data_source' => 'test-smoke',
            ]
        );
        $accounts['student']->load('mahasiswaProfile');

        return $accounts;
    }

    private function smokeUser(string $email, array $attributes): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            array_merge([
                'password' => self::SMOKE_PASSWORD,
                'status' => UserStatus::Active,
                'nip' => null,
                'sub_role' => null,
                'tendik_role' => null,
                'role_level' => null,
                'study_program_id' => null,
                'department_id' => null,
                'assigned_tasks' => null,
            ], $attributes)
        );
    }

    private function letterCases(): array
    {
        return [
            'beasiswa' => [
                'factory' => 'scholarshipApplication',
                'route' => 'surat-permohonan-beasiswa',
                'letter_type' => ScholarshipApplication::LETTER_TYPE,
                'model' => ScholarshipApplication::class,
            ],
            'aktif' => [
                'factory' => 'aktifApplication',
                'route' => 'surat-keterangan-aktif',
                'letter_type' => SuratKeteranganAktifApplication::LETTER_TYPE,
                'model' => SuratKeteranganAktifApplication::class,
            ],
            'magang' => [
                'factory' => 'magangApplication',
                'route' => 'surat-pengantar-magang',
                'letter_type' => SuratPengantarMagangApplication::LETTER_TYPE,
                'model' => SuratPengantarMagangApplication::class,
            ],
            'pln' => [
                'factory' => 'prosesLuarNegeriApplication',
                'route' => 'proses-luar-negeri',
                'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
                'model' => ProsesLuarNegeriApplication::class,
            ],
        ];
    }

    private function makeApplication(array $case, User $student, string $status, array $attributes = []): Model
    {
        $factory = $case['factory'];

        return $this->{$factory}($student, array_merge([
            'status' => $status,
        ], $attributes));
    }

    private function workflowStatus(array $case, string $constant): string
    {
        return constant($case['model'] . "::{$constant}");
    }

    private function showUrl(array $case, Model $application): string
    {
        return "/api/akademik/{$case['route']}/{$application->id}";
    }

    private function actionUrl(array $case, Model $application, string $action): string
    {
        return "{$this->showUrl($case, $application)}/{$action}";
    }

    private function assertForbiddenDetailDoesNotLeak(array $payload): void
    {
        foreach (['application', 'student', 'docx_url', 'document', 'generated_docx_path', 'generated_pdf_path'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }

    private function assertTaskPresent(array $tasks, string $letterType, int $id): void
    {
        $this->assertTrue(
            collect($tasks)->contains(fn (array $row): bool => $row['letter_type'] === $letterType && $row['id'] === $id),
            "Expected {$letterType} task {$id} to be present."
        );
    }

    private function assertTaskMissing(array $tasks, string $letterType, int $id): void
    {
        $this->assertFalse(
            collect($tasks)->contains(fn (array $row): bool => $row['letter_type'] === $letterType && $row['id'] === $id),
            "Expected {$letterType} task {$id} to be absent."
        );
    }
}
