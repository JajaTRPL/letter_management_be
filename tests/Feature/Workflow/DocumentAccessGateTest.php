<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Models\ProsesLuarNegeriApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentAccessGateTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_preview_is_blocked_before_student_review_status(): void
    {
        [$student] = $this->completeMahasiswa();

        $scholarship = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $magang = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $aktif = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $prosesLuarNegeri = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$scholarship->id}/preview")
            ->assertNotFound();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-pengantar-magang/{$magang->id}/preview")
            ->assertNotFound();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$aktif->id}/preview")
            ->assertNotFound();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$prosesLuarNegeri->id}/preview")
            ->assertNotFound();
    }

    public function test_beasiswa_and_magang_legacy_preview_routes_are_retired_at_student_review_status(): void
    {
        [$student] = $this->completeMahasiswa();

        $scholarship = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $magang = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $prosesLuarNegeri = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-permohonan-beasiswa/{$scholarship->id}/preview")
            ->assertNotFound();

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-pengantar-magang/{$magang->id}/preview")
            ->assertNotFound();

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$prosesLuarNegeri->id}/preview")
            ->assertNotFound();
    }

    public function test_aktif_legacy_preview_route_is_retired(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/preview")
            ->assertNotFound();
    }

    public function test_magang_complete_is_blocked_until_private_mahasiswa_artifact_exists(): void
    {
        Storage::fake('local');
        [$student] = $this->completeMahasiswa();

        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-pengantar-magang/{$application->id}/complete")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
        ]);
    }

    public function test_aktif_complete_is_blocked_until_private_mahasiswa_artifact_exists(): void
    {
        Storage::fake('local');
        [$student] = $this->completeMahasiswa();

        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/complete")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
        ]);
    }

    public function test_proses_luar_negeri_complete_is_blocked_until_private_mahasiswa_artifact_exists(): void
    {
        Storage::fake('local');
        [$student] = $this->completeMahasiswa();

        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertNotFound()
            ->assertJsonPath('reason', 'artifact_unavailable');

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
        ]);
    }

    public function test_aktif_detail_returns_null_generated_pdf_compatibility_field(): void
    {
        [$student] = $this->completeMahasiswa();

        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.generated_pdf_path', null);

        $application->update([
            'status' => SuratKeteranganAktifApplication::STATUS_COMPLETED,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.generated_pdf_path', null);
    }

    public function test_magang_detail_returns_null_generated_pdf_compatibility_field(): void
    {
        [$student] = $this->completeMahasiswa();
        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_COMPLETED,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-pengantar-magang/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.generated_pdf_path', null);
    }

    public function test_proses_luar_negeri_detail_returns_null_generated_pdf_compatibility_field(): void
    {
        [$student] = $this->completeMahasiswa();

        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.generated_pdf_path', null);

        $application->update([
            'status' => ProsesLuarNegeriApplication::STATUS_COMPLETED,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$application->id}")
            ->assertOk()
            ->assertJsonPath('application.generated_pdf_path', null);
    }

    public function test_raw_storage_routes_block_generated_document_folders(): void
    {
        $apiUrls = [
            '/api/storage/surat-pengantar-magang/generated/final.pdf',
            '/api/storage/surat-keterangan-aktif/generated/final.pdf',
            '/api/storage/proses-luar-negeri/generated/final.pdf',
            '/api/storage/scholarships/final.docx',
        ];

        foreach ($apiUrls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }

        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF-1.4 test');
        Storage::disk('public')->put('surat-keterangan-aktif/generated/final.pdf', '%PDF-1.4 test');
        Storage::disk('public')->put('proses-luar-negeri/generated/final.pdf', '%PDF-1.4 test');
        Storage::disk('public')->put('scholarships/final.docx', 'docx test');

        foreach ($apiUrls as $url) {
            $this->actingAs($student, 'sanctum')
                ->get($url)
                ->assertForbidden();
        }

        $publicUrls = [
            '/storage/surat-pengantar-magang/generated/final.pdf',
            '/storage/surat-keterangan-aktif/generated/final.pdf',
            '/storage/proses-luar-negeri/generated/final.pdf',
            '/storage/scholarships/final.docx',
        ];

        foreach ($publicUrls as $url) {
            $this->get($url)->assertForbidden();
        }
    }
}
