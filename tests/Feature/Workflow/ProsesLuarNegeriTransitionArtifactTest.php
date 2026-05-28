<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterDocumentArtifact;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Services\ProsesLuarNegeriDocumentGenerationService;
use App\Services\ProsesLuarNegeriPreviewGenerationException;
use App\Services\ProsesLuarNegeriPreviewGenerationService;
use App\Services\ProsesLuarNegeriService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * PLN.4 transition wiring contract: workflow transitions generate the matching
 * private phase artifact before status mutation, then commit only after a
 * locked row recheck. PLN.9 retires the public generated document bridge;
 * private artifacts are the only runtime document source.
 */
class ProsesLuarNegeriTransitionArtifactTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-23 09:10:20'));
        Cache::flush();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_submit_generates_tendik_review_artifact_before_status_mutation(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
        ]);

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_TENDIK_REVIEW,
            function (array $overrides, ?int $generatedBy) use ($student): void {
                $this->assertSame(ProsesLuarNegeriApplication::STATUS_SUBMITTED, $overrides['status']);
                $this->assertSame('2026-05-23 09:10:20', $overrides['submitted_at']->toDateTimeString());
                $this->assertSame('2026-05-23 09:10:20', $overrides['tanggal_surat']->toDateTimeString());
                $this->assertSame($student->id, $generatedBy);
            },
        );

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/proses-luar-negeri/submit')
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_SUBMITTED)
            ->assertJsonPath('assigned_to', $tendik->name);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertSame('2026-05-23 09:10:20', $fresh->submitted_at?->toDateTimeString());
        $this->assertSame($tendik->id, $fresh->assigned_to);
    }

    public function test_revision_submit_generates_tendik_review_artifact(): void
    {
        $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_REVISION,
            'submitted_at' => Carbon::parse('2026-05-20 08:00:00'),
            'revision_note' => 'Perbaiki keperluan.',
            'rejection_reason' => 'Legacy rejection note',
        ]);

        $this->expectPreviewGenerationCall(LetterDocumentArtifact::PHASE_TENDIK_REVIEW);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/proses-luar-negeri/submit')
            ->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->revision_note);
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_submit_artifact_failure_leaves_status_unchanged(): void
    {
        $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_DRAFT,
            'submitted_at' => null,
            'assigned_to' => null,
        ]);

        $this->mockPreviewGenerationThrows();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/proses-luar-negeri/submit')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Dokumen pratinjau pengajuan belum dapat dibuat. Silakan coba lagi.');

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->assigned_to);
    }

    public function test_tendik_approve_generates_prodi_review_artifact_with_pending_nomor_surat(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            function (array $overrides, ?int $generatedBy) use ($tendik): void {
                $this->assertSame(ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK, $overrides['status']);
                $this->assertSame('PLN-WIRE-001', $overrides['nomor_surat']);
                $this->assertSame('2026-05-23 09:10:20', $overrides['tendik_approved_at']->toDateTimeString());
                $this->assertSame($tendik->id, $overrides['tendik_approved_by']);
                $this->assertSame('2026-05-23 09:10:20', $overrides['tanggal_surat']->toDateTimeString());
                $this->assertSame($tendik->id, $generatedBy);
            },
        );

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/approve", [
                'nomor_surat' => 'PLN-WIRE-001',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK);

        $fresh = $application->fresh();
        $this->assertSame('PLN-WIRE-001', $fresh->nomor_surat);
        $this->assertSame($tendik->id, $fresh->tendik_approved_by);
        $this->assertSame('2026-05-23 09:10:20', $fresh->tendik_approved_at?->toDateTimeString());
    }

    public function test_tendik_approve_artifact_failure_leaves_status_nomor_and_actor_unchanged(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $this->mockPreviewGenerationThrows();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/approve", [
                'nomor_surat' => 'PLN-FAIL',
            ])
            ->assertStatus(503);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_SUBMITTED, $fresh->status);
        $this->assertNull($fresh->nomor_surat);
        $this->assertNull($fresh->tendik_approved_at);
        $this->assertNull($fresh->tendik_approved_by);
    }

    public function test_kaprodi_approve_generates_departemen_review_artifact(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'PLN-WIRE-002',
            'tendik_approved_at' => Carbon::parse('2026-05-22 08:00:00'),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_DEPARTEMEN_REVIEW,
            function (array $overrides, ?int $generatedBy = null) use ($kaprodi): void {
                $this->assertSame(ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI, $overrides['status']);
                $this->assertSame($kaprodi->id, $overrides['kaprodi_approved_by']);
                $this->assertSame('2026-05-23 09:10:20', $overrides['kaprodi_approved_at']->toDateTimeString());
                $this->assertSame('2026-05-22 08:00:00', $overrides['tanggal_surat']->toDateTimeString());
            },
        );

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI);

        $fresh = $application->fresh();
        $this->assertSame($kaprodi->id, $fresh->kaprodi_approved_by);
        $this->assertSame('2026-05-23 09:10:20', $fresh->kaprodi_approved_at?->toDateTimeString());
    }

    public function test_kaprodi_approve_failure_and_wrong_prodi_do_not_persist(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'PLN-WIRE-003',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->mockPreviewGenerationThrows();

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertStatus(503);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertNull($fresh->kaprodi_approved_at);
        $this->assertNull($fresh->kaprodi_approved_by);

        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$otherStudent] = $this->completeMahasiswa([], [], $studentProgram);
        $wrongProgram = $this->studyProgram($this->department());
        $wrongKaprodi = $this->akademik('kaprodi', ['study_program_id' => $wrongProgram->id]);
        $wrongScopeApplication = $this->prosesLuarNegeriApplication($otherStudent, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'PLN-WRONG-PRODI',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
        ]);

        $this->mockPreviewGenerationNever();

        $this->actingAs($wrongKaprodi, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$wrongScopeApplication->id}/approve")
            ->assertForbidden();

        $this->assertNull($wrongScopeApplication->fresh()->kaprodi_approved_by);
    }

    public function test_kadep_approve_generates_mahasiswa_review_artifact_without_legacy_pdf_bridge(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'PLN-WIRE-004',
            'tendik_approved_at' => Carbon::parse('2026-05-22 08:00:00'),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => Carbon::parse('2026-05-22 09:00:00'),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->expectPreviewGenerationCall(
            LetterDocumentArtifact::PHASE_MAHASISWA_REVIEW,
            function (array $overrides, ?int $generatedBy = null) use ($kadep): void {
                $this->assertSame(ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW, $overrides['status']);
                $this->assertSame($kadep->id, $overrides['kadep_approved_by']);
                $this->assertSame('2026-05-23 09:10:20', $overrides['kadep_approved_at']->toDateTimeString());
                $this->assertSame('2026-05-22 08:00:00', $overrides['tanggal_surat']->toDateTimeString());
                $this->assertInstanceOf(User::class, $overrides['official_kadep']);
            },
        );
        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW)
            ->assertJsonPath('application.generated_pdf_path', null);

        $fresh = $application->fresh();
        $this->assertSame($kadep->id, $fresh->kadep_approved_by);
        $this->assertSame([], Storage::disk('public')->allFiles('proses-luar-negeri/generated'));
    }

    public function test_kadep_approve_artifact_failure_and_wrong_department_do_not_persist(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            'assigned_to' => $tendik->id,
            'nomor_surat' => 'PLN-WIRE-006',
            'tendik_approved_at' => now(),
            'tendik_approved_by' => $tendik->id,
            'kaprodi_approved_at' => now(),
            'kaprodi_approved_by' => $kaprodi->id,
        ]);

        $this->mockPreviewGenerationThrows();
        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertStatus(503);

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI, $fresh->status);
        $this->assertNull($fresh->kadep_approved_at);
        $this->assertNull($fresh->kadep_approved_by);

        $studentDept = $this->department();
        $studentProgram = $this->studyProgram($studentDept);
        [$otherStudent] = $this->completeMahasiswa([], [], $studentProgram);
        $wrongKadep = $this->akademik('kadep', ['department_id' => $this->department()->id]);
        $wrongScopeApplication = $this->prosesLuarNegeriApplication($otherStudent, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            'nomor_surat' => 'PLN-WRONG-DEPT',
            'tendik_approved_at' => now(),
            'kaprodi_approved_at' => now(),
        ]);

        $this->mockPreviewGenerationNever();

        $this->actingAs($wrongKadep, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$wrongScopeApplication->id}/approve")
            ->assertForbidden();

        $this->assertNull($wrongScopeApplication->fresh()->kadep_approved_by);
    }

    public function test_pln_legacy_pdf_generator_is_removed_from_runtime_service(): void
    {
        $this->assertFalse(method_exists(app(ProsesLuarNegeriService::class), 'generateDocument'));

        $controller = file_get_contents(app_path('Http/Controllers/ProsesLuarNegeriController.php'));
        $this->assertNotFalse($controller);
        $this->assertStringNotContainsString('->generateDocument(', $controller);
        $this->assertStringNotContainsString('deleteGeneratedDocument(', $controller);
    }

    public function test_ready_artifact_cache_hit_does_not_duplicate_artifact_on_transition(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $preGenerator = new PhasePln4FakeDocumentGenerationService();
        $preConverter = new PhasePln4FakeDocumentConverter();
        $this->bindPreviewStack($preGenerator, $preConverter);

        $ready = app(ProsesLuarNegeriPreviewGenerationService::class)->generateForPhase(
            $application->fresh(),
            LetterDocumentArtifact::PHASE_PRODI_REVIEW,
            [
                'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
                'nomor_surat' => 'PLN-CACHE-001',
                'tendik_approved_at' => Carbon::now(),
                'tendik_approved_by' => $tendik->id,
                'tanggal_surat' => Carbon::now(),
            ],
            $tendik->id,
        );

        $transitionGenerator = new PhasePln4FakeDocumentGenerationService();
        $transitionConverter = new PhasePln4FakeDocumentConverter();
        $this->bindPreviewStack($transitionGenerator, $transitionConverter);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/approve", [
                'nomor_surat' => 'PLN-CACHE-001',
            ])
            ->assertOk();

        $this->assertSame(0, $transitionGenerator->calls);
        $this->assertSame(0, $transitionConverter->calls);
        $this->assertSame(1, LetterDocumentArtifact::query()
            ->where('source_hash', $ready->source_hash)
            ->where('status', LetterDocumentArtifact::STATUS_READY)
            ->count());
    }

    public function test_recheck_conflict_after_artifact_generation_does_not_overwrite_state(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication(null, [
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'assigned_to' => $tendik->id,
            'nomor_surat' => null,
            'tendik_approved_at' => null,
            'tendik_approved_by' => null,
        ]);

        $mock = Mockery::mock(ProsesLuarNegeriPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function () use ($application): LetterDocumentArtifact {
                ProsesLuarNegeriApplication::whereKey($application->getKey())
                    ->update(['status' => ProsesLuarNegeriApplication::STATUS_REJECTED]);

                return LetterDocumentArtifact::make([
                    'phase' => LetterDocumentArtifact::PHASE_PRODI_REVIEW,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(ProsesLuarNegeriPreviewGenerationService::class, $mock);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/approve", [
                'nomor_surat' => 'PLN-RACE-001',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pengajuan sudah berubah dan tidak dapat diverifikasi ulang.');

        $fresh = $application->fresh();
        $this->assertSame(ProsesLuarNegeriApplication::STATUS_REJECTED, $fresh->status);
        $this->assertNull($fresh->nomor_surat);
        $this->assertNull($fresh->tendik_approved_at);
        $this->assertNull($fresh->tendik_approved_by);
    }

    private function expectPreviewGenerationCall(
        string $phase,
        ?callable $assertion = null,
    ): Mockery\MockInterface {
        $mock = Mockery::mock(ProsesLuarNegeriPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andReturnUsing(function ($application, string $actualPhase, array $overrides = [], ?int $generatedBy = null) use ($phase, $assertion) {
                $this->assertSame($phase, $actualPhase);
                if ($assertion) {
                    $assertion($overrides, $generatedBy);
                }

                return LetterDocumentArtifact::make([
                    'letter_type' => ProsesLuarNegeriApplication::LETTER_TYPE,
                    'application_id' => $application->getKey(),
                    'phase' => $actualPhase,
                    'status' => LetterDocumentArtifact::STATUS_READY,
                ]);
            });
        $this->app->instance(ProsesLuarNegeriPreviewGenerationService::class, $mock);

        return $mock;
    }

    private function mockPreviewGenerationThrows(): void
    {
        $mock = Mockery::mock(ProsesLuarNegeriPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')
            ->once()
            ->andThrow(new ProsesLuarNegeriPreviewGenerationException('forced PLN preview failure'));
        $this->app->instance(ProsesLuarNegeriPreviewGenerationService::class, $mock);
    }

    private function mockPreviewGenerationNever(): void
    {
        $mock = Mockery::mock(ProsesLuarNegeriPreviewGenerationService::class);
        $mock->shouldReceive('generateForPhase')->never();
        $this->app->instance(ProsesLuarNegeriPreviewGenerationService::class, $mock);
    }

    private function bindPreviewStack(
        PhasePln4FakeDocumentGenerationService $generator,
        PhasePln4FakeDocumentConverter $converter,
    ): void {
        $this->app->instance(ProsesLuarNegeriDocumentGenerationService::class, $generator);
        $this->app->instance(DocumentConverter::class, $converter);
        $this->app->forgetInstance(ProsesLuarNegeriPreviewGenerationService::class);
    }
}

class PhasePln4FakeDocumentGenerationService extends ProsesLuarNegeriDocumentGenerationService
{
    public int $calls = 0;

    public function __construct()
    {
    }

    public function generateDocumentForPhase(
        ProsesLuarNegeriApplication $application,
        string $phase,
        array $overrides = [],
    ): string {
        $this->calls++;

        $path = 'letter-document-artifacts/'
            . ProsesLuarNegeriApplication::LETTER_TYPE
            . '/'
            . $application->id
            . '/'
            . $phase
            . '/source_pln4_fake_'
            . $this->calls
            . '.docx';
        Storage::disk('local')->put($path, 'fake PLN docx');

        return $path;
    }
}

class PhasePln4FakeDocumentConverter implements DocumentConverter
{
    public int $calls = 0;

    public function convertDocxToPdf(string $sourceDocxAbsolutePath, string $destPdfAbsolutePath): void
    {
        $this->calls++;

        file_put_contents($destPdfAbsolutePath, "%PDF-1.4\nfake");
    }
}
