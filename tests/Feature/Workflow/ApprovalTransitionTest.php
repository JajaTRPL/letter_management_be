<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Services\ProsesLuarNegeriService;
use App\Services\ScholarshipAutomationService;
use App\Services\SuratKeteranganAktifService;
use App\Services\SuratPengantarMagangService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ApprovalTransitionTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_aktif_uses_canonical_approval_flow_through_student_completion(): void
    {
        Notification::fake();
        Storage::fake('public');

        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/approve", [
                'nomor_surat' => 'AKT-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->mockAktifDocumentGeneration();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/surat-keterangan-aktif/generated/final.pdf',
        ]);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_COMPLETED,
        ]);
    }

    public function test_aktif_tendik_approval_requires_nomor_surat(): void
    {
        $tendik = $this->tendikPersuratan([SuratKeteranganAktifApplication::LETTER_TYPE]);
        $application = $this->aktifApplication();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-keterangan-aktif/{$application->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nomor_surat']);

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_SUBMITTED,
            'nomor_surat' => null,
        ]);
    }

    public function test_proses_luar_negeri_uses_canonical_approval_flow_through_student_completion(): void
    {
        Notification::fake();
        Storage::fake('public');

        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->prosesLuarNegeriApplication($student);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/approve", [
                'nomor_surat' => 'PLN-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->mockProsesLuarNegeriDocumentGeneration();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/proses-luar-negeri/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/proses-luar-negeri/generated/final.pdf',
        ]);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$application->id}/preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_COMPLETED,
        ]);
    }

    public function test_proses_luar_negeri_tendik_approval_requires_nomor_surat(): void
    {
        $tendik = $this->tendikPersuratan([ProsesLuarNegeriApplication::LETTER_TYPE]);
        $application = $this->prosesLuarNegeriApplication();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/proses-luar-negeri/{$application->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nomor_surat']);

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_SUBMITTED,
            'nomor_surat' => null,
        ]);
    }

    public function test_magang_uses_canonical_approval_flow_through_student_completion(): void
    {
        Notification::fake();
        Storage::fake('public');

        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat' => 'MAG-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'assigned_to' => $tendik->id,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->mockMagangDocumentGeneration();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-pengantar-magang/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/surat-pengantar-magang/generated/final.pdf',
        ]);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-pengantar-magang/{$application->id}/preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-pengantar-magang/{$application->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_COMPLETED,
        ]);
    }

    public function test_beasiswa_uses_canonical_approval_flow_through_student_completion(): void
    {
        Notification::fake();
        Storage::fake('public');

        $tendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $kaprodi = $this->akademik('kaprodi');
        $kadep = $this->akademik('kadep');
        [$student] = $this->completeMahasiswa();
        $application = $this->scholarshipApplication($student);

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-permohonan-beasiswa/{$application->id}/approve", [
                'nomor_surat' => 'BEA-001',
            ])
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_TENDIK,
        ]);

        $this->actingAs($kaprodi, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->mockScholarshipDocumentGeneration();

        $this->actingAs($kadep, 'sanctum')
            ->patchJson("/api/akademik/surat-permohonan-beasiswa/{$application->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_docx_path' => 'scholarships/final.docx',
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-permohonan-beasiswa/{$application->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => ScholarshipApplication::STATUS_COMPLETED,
        ]);
    }

    private function mockMagangDocumentGeneration(): void
    {
        $mock = Mockery::mock(SuratPengantarMagangService::class);
        $mock->shouldReceive('generateDocument')
            ->once()
            ->andReturnUsing(function (SuratPengantarMagangApplication $application): string {
                $path = 'surat-pengantar-magang/generated/final.pdf';
                Storage::disk('public')->put($path, '%PDF-1.4 test');
                $application->update(['generated_pdf_path' => Storage::url($path)]);

                return Storage::url($path);
            });
        $mock->shouldReceive('generatedPdfStoragePath')->andReturnUsing(
            fn (SuratPengantarMagangApplication $application): ?string => 'surat-pengantar-magang/generated/final.pdf'
        );

        $this->app->instance(SuratPengantarMagangService::class, $mock);
    }

    private function mockAktifDocumentGeneration(): void
    {
        $mock = Mockery::mock(SuratKeteranganAktifService::class);
        $mock->shouldReceive('generateDocument')
            ->once()
            ->andReturnUsing(function (SuratKeteranganAktifApplication $application): string {
                $path = 'surat-keterangan-aktif/generated/final.pdf';
                Storage::disk('public')->put($path, '%PDF-1.4 test');
                $application->update(['generated_pdf_path' => Storage::url($path)]);

                return Storage::url($path);
            });

        $this->app->instance(SuratKeteranganAktifService::class, $mock);
    }

    private function mockProsesLuarNegeriDocumentGeneration(): void
    {
        $mock = Mockery::mock(ProsesLuarNegeriService::class);
        $mock->shouldReceive('generateDocument')
            ->once()
            ->andReturnUsing(function (ProsesLuarNegeriApplication $application): string {
                $path = 'proses-luar-negeri/generated/final.pdf';
                Storage::disk('public')->put($path, '%PDF-1.4 test');
                $application->update(['generated_pdf_path' => Storage::url($path)]);

                return Storage::url($path);
            });

        $this->app->instance(ProsesLuarNegeriService::class, $mock);
    }

    private function mockScholarshipDocumentGeneration(): void
    {
        $mock = Mockery::mock(ScholarshipAutomationService::class);
        $mock->shouldReceive('generateDocument')
            ->once()
            ->andReturnUsing(function (): string {
                Storage::disk('public')->put('scholarships/final.docx', 'docx test');

                return 'scholarships/final.docx';
            });
        $mock->shouldReceive('deleteGeneratedDocument')->never();

        $this->app->instance(ScholarshipAutomationService::class, $mock);
    }
}
