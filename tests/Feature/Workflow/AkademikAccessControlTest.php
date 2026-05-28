<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AkademikAccessControlTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_akademik_detail_access_is_scope_scoped_for_all_letters(): void
    {
        // Detail read access is scope-only: same-prodi for Kaprodi/Sekprodi,
        // same-department for Kadep/Sekdep, regardless of workflow status.
        // Action authorization remains stage-gated (see approve/reject tests).
        foreach ($this->letterCases() as $case) {
            $fixtures = $this->scopedFixtures();

            $prodiApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'));

            $this->actingAs($fixtures['kaprodiTrpl'], 'sanctum')
                ->getJson($this->showUrl($case, $prodiApplication))
                ->assertOk();

            $this->actingAs($fixtures['sekprodiTrpl'], 'sanctum')
                ->getJson($this->showUrl($case, $prodiApplication))
                ->assertOk();

            $wrongProdiResponse = $this->actingAs($fixtures['kaprodiTre'], 'sanctum')
                ->getJson($this->showUrl($case, $prodiApplication))
                ->assertForbidden();
            $this->assertForbiddenDetailDoesNotLeak($wrongProdiResponse);

            // Kadep in the SAME department as the TRPL student may VIEW the
            // application at any status, including the Prodi-actionable stage.
            // Action gating elsewhere prevents Kadep from acting at this stage.
            $this->actingAs($fixtures['kadepDtedi'], 'sanctum')
                ->getJson($this->showUrl($case, $prodiApplication))
                ->assertOk();

            $departmentApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'));

            $this->actingAs($fixtures['kadepDtedi'], 'sanctum')
                ->getJson($this->showUrl($case, $departmentApplication))
                ->assertOk();

            $this->actingAs($fixtures['sekdepDtedi'], 'sanctum')
                ->getJson($this->showUrl($case, $departmentApplication))
                ->assertOk();

            $wrongDepartmentResponse = $this->actingAs($fixtures['kadepOther'], 'sanctum')
                ->getJson($this->showUrl($case, $departmentApplication))
                ->assertForbidden();
            $this->assertForbiddenDetailDoesNotLeak($wrongDepartmentResponse);

            // Kaprodi in the SAME prodi as the TRPL student may VIEW the
            // application at any status, including the Department-actionable
            // stage. Action gating elsewhere prevents Kaprodi from acting then.
            $this->actingAs($fixtures['kaprodiTrpl'], 'sanctum')
                ->getJson($this->showUrl($case, $departmentApplication))
                ->assertOk();
        }
    }

    public function test_akademik_detail_read_access_covers_processed_history_statuses(): void
    {
        // Read access must follow the user past their actionable stage so the
        // Riwayat "Lihat Detail" entry point works for Kaprodi/Kadep on rows
        // they previously acted on (Approved_Kaprodi, Ready_For_Student_Review,
        // Completed) as well as terminal off-paths (Rejected, Revision).
        $historyStatuses = [
            'STATUS_READY_FOR_STUDENT_REVIEW',
            'STATUS_COMPLETED',
            'STATUS_REJECTED',
            'STATUS_REVISION',
        ];

        foreach ($this->letterCases() as $case) {
            foreach ($historyStatuses as $statusConstant) {
                $fixtures = $this->scopedFixtures();

                $application = $this->makeApplication(
                    $case,
                    $fixtures['trplStudent'],
                    $this->workflowStatus($case, $statusConstant),
                );

                $this->actingAs($fixtures['kaprodiTrpl'], 'sanctum')
                    ->getJson($this->showUrl($case, $application))
                    ->assertOk();

                $this->actingAs($fixtures['sekprodiTrpl'], 'sanctum')
                    ->getJson($this->showUrl($case, $application))
                    ->assertOk();

                $this->actingAs($fixtures['kadepDtedi'], 'sanctum')
                    ->getJson($this->showUrl($case, $application))
                    ->assertOk();

                $this->actingAs($fixtures['sekdepDtedi'], 'sanctum')
                    ->getJson($this->showUrl($case, $application))
                    ->assertOk();

                $this->actingAs($fixtures['kaprodiTre'], 'sanctum')
                    ->getJson($this->showUrl($case, $application))
                    ->assertForbidden();

                $this->actingAs($fixtures['kadepOther'], 'sanctum')
                    ->getJson($this->showUrl($case, $application))
                    ->assertForbidden();
            }
        }
    }

    public function test_akademik_history_read_access_does_not_grant_action(): void
    {
        // Verifies the read/action split: a scoped Kaprodi can view a Completed
        // application but cannot trigger /approve on it (status-gated 422).
        $fixtures = $this->scopedFixtures();

        foreach ($this->letterCases() as $case) {
            $completedApplication = $this->makeApplication(
                $case,
                $fixtures['trplStudent'],
                $this->workflowStatus($case, 'STATUS_COMPLETED'),
            );

            $this->actingAs($fixtures['kaprodiTrpl'], 'sanctum')
                ->getJson($this->showUrl($case, $completedApplication))
                ->assertOk();

            $this->actingAs($fixtures['kaprodiTrpl'], 'sanctum')
                ->patchJson($this->actionUrl($case, $completedApplication, 'approve'))
                ->assertStatus(422);
        }
    }

    public function test_akademik_approve_is_stage_and_scope_scoped_for_all_letters(): void
    {
        Notification::fake();
        // SKA approvals now wire to the SKA preview pipeline; mock it permissively.
        $this->mockSkaPreviewGenerationAlwaysReady();
        $this->mockPlnPreviewGenerationAlwaysReady();
        $this->mockMagangPreviewGenerationAlwaysReady();

        foreach ($this->letterCases() as $case) {
            $fixtures = $this->scopedFixtures();

            $prodiApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'));
            if ($case['model'] === ScholarshipApplication::class) {
                $this->mockBeasiswaPreviewGenerationForProdiApprove();
            }
            $this->actingAs($fixtures['sekprodiTrpl'], 'sanctum')
                ->patchJson($this->actionUrl($case, $prodiApplication, 'approve'))
                ->assertOk();
            $this->assertDatabaseHas($prodiApplication->getTable(), [
                'id' => $prodiApplication->id,
                'status' => $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'),
                'kaprodi_approved_by' => $fixtures['sekprodiTrpl']->id,
            ]);

            $wrongProdiApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'));
            $this->actingAs($fixtures['kaprodiTre'], 'sanctum')
                ->patchJson($this->actionUrl($case, $wrongProdiApplication, 'approve'))
                ->assertForbidden();
            $this->assertDatabaseHas($wrongProdiApplication->getTable(), [
                'id' => $wrongProdiApplication->id,
                'status' => $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'),
            ]);

            $departmentAtProdiStageApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'));
            $this->actingAs($fixtures['kadepDtedi'], 'sanctum')
                ->patchJson($this->actionUrl($case, $departmentAtProdiStageApplication, 'approve'))
                ->assertUnprocessable();

            $departmentApplication = $this->makeApplication(
                $case,
                $fixtures['trplStudent'],
                $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI')
            );
            $this->mockDocumentGenerationForDepartmentApproval($case);
            $this->actingAs($fixtures['kadepDtedi'], 'sanctum')
                ->patchJson($this->actionUrl($case, $departmentApplication, 'approve'))
                ->assertOk();
            $this->assertDatabaseHas($departmentApplication->getTable(), [
                'id' => $departmentApplication->id,
                'status' => $this->workflowStatus($case, 'STATUS_READY_FOR_STUDENT_REVIEW'),
                'kadep_approved_by' => $fixtures['kadepDtedi']->id,
            ]);

            $wrongDepartmentApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'));
            $this->actingAs($fixtures['kadepOther'], 'sanctum')
                ->patchJson($this->actionUrl($case, $wrongDepartmentApplication, 'approve'))
                ->assertForbidden();
            $this->assertDatabaseHas($wrongDepartmentApplication->getTable(), [
                'id' => $wrongDepartmentApplication->id,
                'status' => $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'),
            ]);

            $prodiAtDepartmentStageApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'));
            $this->actingAs($fixtures['kaprodiTrpl'], 'sanctum')
                ->patchJson($this->actionUrl($case, $prodiAtDepartmentStageApplication, 'approve'))
                ->assertUnprocessable();
        }
    }

    public function test_akademik_reject_and_revise_are_stage_and_scope_scoped_for_all_letters(): void
    {
        Notification::fake();

        foreach ($this->letterCases() as $case) {
            $fixtures = $this->scopedFixtures();

            $correctProdiRejectApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'));
            $this->actingAs($fixtures['kaprodiTrpl'], 'sanctum')
                ->patchJson($this->actionUrl($case, $correctProdiRejectApplication, 'reject'), ['reason' => 'Tidak sesuai.'])
                ->assertOk();
            $this->assertDatabaseHas($correctProdiRejectApplication->getTable(), [
                'id' => $correctProdiRejectApplication->id,
                'status' => $this->workflowStatus($case, 'STATUS_REJECTED'),
            ]);

            $correctDepartmentReviseApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'));
            $this->actingAs($fixtures['kadepDtedi'], 'sanctum')
                ->patchJson($this->actionUrl($case, $correctDepartmentReviseApplication, 'revise'), ['note' => 'Lengkapi dokumen.'])
                ->assertOk();
            $this->assertDatabaseHas($correctDepartmentReviseApplication->getTable(), [
                'id' => $correctDepartmentReviseApplication->id,
                'status' => $this->workflowStatus($case, 'STATUS_REVISION'),
            ]);

            foreach (['reject' => ['reason' => 'Tidak sesuai.'], 'revise' => ['note' => 'Lengkapi dokumen.']] as $action => $payload) {
                $wrongProdiApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'));
                $this->actingAs($fixtures['kaprodiTre'], 'sanctum')
                    ->patchJson($this->actionUrl($case, $wrongProdiApplication, $action), $payload)
                    ->assertForbidden();
                $this->assertDatabaseHas($wrongProdiApplication->getTable(), [
                    'id' => $wrongProdiApplication->id,
                    'status' => $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'),
                ]);

                $wrongDepartmentApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'));
                $this->actingAs($fixtures['kadepOther'], 'sanctum')
                    ->patchJson($this->actionUrl($case, $wrongDepartmentApplication, $action), $payload)
                    ->assertForbidden();
                $this->assertDatabaseHas($wrongDepartmentApplication->getTable(), [
                    'id' => $wrongDepartmentApplication->id,
                    'status' => $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'),
                ]);

                $prodiAtDepartmentStageApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'));
                $this->actingAs($fixtures['kaprodiTrpl'], 'sanctum')
                    ->patchJson($this->actionUrl($case, $prodiAtDepartmentStageApplication, $action), $payload)
                    ->assertUnprocessable();

                $departmentAtProdiStageApplication = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'));
                $this->actingAs($fixtures['kadepDtedi'], 'sanctum')
                    ->patchJson($this->actionUrl($case, $departmentAtProdiStageApplication, $action), $payload)
                    ->assertUnprocessable();
            }
        }
    }

    public function test_akademik_riwayat_is_scoped_for_prodi_and_department(): void
    {
        foreach ($this->letterCases() as $case) {
            $fixtures = $this->scopedFixtures();

            $prodiHistory = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'), [
                'tendik_approved_at' => now()->subDay(),
                'kaprodi_approved_at' => now(),
            ]);
            $prodiActive = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_TENDIK'), [
                'tendik_approved_at' => now()->subDay(),
            ]);
            $otherProdiHistory = $this->makeApplication($case, $fixtures['treStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'), [
                'tendik_approved_at' => now()->subDay(),
                'kaprodi_approved_at' => now(),
            ]);

            $prodiRows = $this->actingAs($fixtures['kaprodiTrpl'], 'sanctum')
                ->getJson('/api/akademik/riwayat')
                ->assertOk()
                ->json('tasks');

            $this->assertTaskPresent($prodiRows, $case['letter_type'], $prodiHistory->id);
            $this->assertTaskMissing($prodiRows, $case['letter_type'], $prodiActive->id);
            $this->assertTaskMissing($prodiRows, $case['letter_type'], $otherProdiHistory->id);

            $departmentHistory = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_READY_FOR_STUDENT_REVIEW'), [
                'tendik_approved_at' => now()->subDays(2),
                'kaprodi_approved_at' => now()->subDay(),
                'kadep_approved_at' => now(),
            ]);
            $departmentActive = $this->makeApplication($case, $fixtures['trplStudent'], $this->workflowStatus($case, 'STATUS_APPROVED_KAPRODI'), [
                'tendik_approved_at' => now()->subDays(2),
                'kaprodi_approved_at' => now()->subDay(),
            ]);
            $otherDepartmentHistory = $this->makeApplication($case, $fixtures['otherStudent'], $this->workflowStatus($case, 'STATUS_READY_FOR_STUDENT_REVIEW'), [
                'tendik_approved_at' => now()->subDays(2),
                'kaprodi_approved_at' => now()->subDay(),
                'kadep_approved_at' => now(),
            ]);

            $departmentRows = $this->actingAs($fixtures['kadepDtedi'], 'sanctum')
                ->getJson('/api/akademik/riwayat')
                ->assertOk()
                ->json('tasks');

            $this->assertTaskPresent($departmentRows, $case['letter_type'], $departmentHistory->id);
            $this->assertTaskMissing($departmentRows, $case['letter_type'], $departmentActive->id);
            $this->assertTaskMissing($departmentRows, $case['letter_type'], $otherDepartmentHistory->id);
        }
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

    private function scopedFixtures(): array
    {
        $dtedi = $this->department();
        $otherDepartment = $this->department();
        $trpl = $this->studyProgram($dtedi);
        $tre = $this->studyProgram($dtedi);
        $otherProgram = $this->studyProgram($otherDepartment);

        [$trplStudent] = $this->completeMahasiswa([], [], $trpl);
        [$treStudent] = $this->completeMahasiswa([], [], $tre);
        [$otherStudent] = $this->completeMahasiswa([], [], $otherProgram);

        return [
            'trplStudent' => $trplStudent,
            'treStudent' => $treStudent,
            'otherStudent' => $otherStudent,
            'kaprodiTrpl' => $this->akademik('kaprodi', ['study_program_id' => $trpl->id]),
            'sekprodiTrpl' => $this->akademik('sekprodi', ['study_program_id' => $trpl->id]),
            'kaprodiTre' => $this->akademik('kaprodi', ['study_program_id' => $tre->id]),
            'kadepDtedi' => $this->akademik('kadep', ['department_id' => $dtedi->id]),
            'sekdepDtedi' => $this->akademik('sekdep', ['department_id' => $dtedi->id]),
            'kadepOther' => $this->akademik('kadep', ['department_id' => $otherDepartment->id]),
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

    private function mockDocumentGenerationForDepartmentApproval(array $case): void
    {
        if ($case['model'] !== ScholarshipApplication::class) {
            return;
        }

        $this->mockBeasiswaPreviewGenerationForDepartmentApprove();
    }

    private function assertForbiddenDetailDoesNotLeak(TestResponse $response): void
    {
        $payload = $response->json();

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
