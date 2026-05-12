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
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        $scholarship = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_APPROVED_KAPRODI,
            'generated_docx_path' => 'scholarships/final.docx',
        ]);
        Storage::disk('public')->put('scholarships/final.docx', 'docx test');

        $magang = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_KAPRODI,
            'generated_pdf_path' => '/storage/surat-pengantar-magang/generated/final.pdf',
        ]);
        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF-1.4 test');

        $aktif = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_APPROVED_KAPRODI,
            'generated_pdf_path' => '/storage/surat-keterangan-aktif/generated/final.pdf',
        ]);
        Storage::disk('public')->put('surat-keterangan-aktif/generated/final.pdf', '%PDF-1.4 test');

        $prosesLuarNegeri = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_APPROVED_KAPRODI,
            'generated_pdf_path' => '/storage/proses-luar-negeri/generated/final.pdf',
        ]);
        Storage::disk('public')->put('proses-luar-negeri/generated/final.pdf', '%PDF-1.4 test');

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-permohonan-beasiswa/{$scholarship->id}/preview")
            ->assertForbidden();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-pengantar-magang/{$magang->id}/preview")
            ->assertUnprocessable();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/surat-keterangan-aktif/{$aktif->id}/preview")
            ->assertUnprocessable();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/mahasiswa/proses-luar-negeri/{$prosesLuarNegeri->id}/preview")
            ->assertUnprocessable();
    }

    public function test_preview_is_allowed_for_owner_at_student_review_status(): void
    {
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        $scholarship = $this->scholarshipApplication($student, [
            'status' => ScholarshipApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_docx_path' => 'scholarships/final.docx',
        ]);
        Storage::disk('public')->put('scholarships/final.docx', 'docx test');

        $magang = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/surat-pengantar-magang/generated/final.pdf',
        ]);
        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF-1.4 test');

        $aktif = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/surat-keterangan-aktif/generated/final.pdf',
        ]);
        Storage::disk('public')->put('surat-keterangan-aktif/generated/final.pdf', '%PDF-1.4 test');

        $prosesLuarNegeri = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/proses-luar-negeri/generated/final.pdf',
        ]);
        Storage::disk('public')->put('proses-luar-negeri/generated/final.pdf', '%PDF-1.4 test');

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-permohonan-beasiswa/{$scholarship->id}/preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-pengantar-magang/{$magang->id}/preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/surat-keterangan-aktif/{$aktif->id}/preview")
            ->assertOk();

        $this->actingAs($student, 'sanctum')
            ->get("/api/mahasiswa/proses-luar-negeri/{$prosesLuarNegeri->id}/preview")
            ->assertOk();
    }

    public function test_magang_complete_is_blocked_until_student_has_previewed_document(): void
    {
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        $application = $this->magangApplication($student, [
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/surat-pengantar-magang/generated/final.pdf',
            'student_reviewed_at' => null,
        ]);
        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF-1.4 test');

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-pengantar-magang/{$application->id}/complete")
            ->assertUnprocessable();

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
        ]);
    }

    public function test_aktif_complete_is_blocked_until_student_has_previewed_document(): void
    {
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/surat-keterangan-aktif/generated/final.pdf',
            'student_reviewed_at' => null,
        ]);
        Storage::disk('public')->put('surat-keterangan-aktif/generated/final.pdf', '%PDF-1.4 test');

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/surat-keterangan-aktif/{$application->id}/complete")
            ->assertUnprocessable();

        $this->assertDatabaseHas('surat_keterangan_aktif_applications', [
            'id' => $application->id,
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
        ]);
    }

    public function test_proses_luar_negeri_complete_is_blocked_until_student_has_previewed_document(): void
    {
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/proses-luar-negeri/generated/final.pdf',
            'student_reviewed_at' => null,
        ]);
        Storage::disk('public')->put('proses-luar-negeri/generated/final.pdf', '%PDF-1.4 test');

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/mahasiswa/proses-luar-negeri/{$application->id}/complete")
            ->assertUnprocessable();

        $this->assertDatabaseHas('proses_luar_negeri_applications', [
            'id' => $application->id,
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'student_reviewed_at' => null,
        ]);
    }

    public function test_aktif_generated_pdf_path_is_hidden_before_completed(): void
    {
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        $application = $this->aktifApplication($student, [
            'status' => SuratKeteranganAktifApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/surat-keterangan-aktif/generated/final.pdf',
        ]);
        Storage::disk('public')->put('surat-keterangan-aktif/generated/final.pdf', '%PDF-1.4 test');

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
            ->assertJsonPath('application.generated_pdf_path', '/storage/surat-keterangan-aktif/generated/final.pdf');
    }

    public function test_proses_luar_negeri_generated_pdf_path_is_hidden_before_completed(): void
    {
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        $application = $this->prosesLuarNegeriApplication($student, [
            'status' => ProsesLuarNegeriApplication::STATUS_READY_FOR_STUDENT_REVIEW,
            'generated_pdf_path' => '/storage/proses-luar-negeri/generated/final.pdf',
        ]);
        Storage::disk('public')->put('proses-luar-negeri/generated/final.pdf', '%PDF-1.4 test');

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
            ->assertJsonPath('application.generated_pdf_path', '/storage/proses-luar-negeri/generated/final.pdf');
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
