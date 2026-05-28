<?php

namespace Tests\Feature\Workflow;

use App\Models\MahasiswaProfile;
use App\Models\ScholarshipApplication;
use App\Models\SuratPengantarMagangApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageAccessSecurityTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_unauthenticated_cannot_access_api_storage(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profiles/fotos/test.jpg', 'img');

        $this->getJson('/api/storage/profiles/fotos/test.jpg')
            ->assertUnauthorized();
    }

    public function test_generated_documents_and_signatures_are_blocked_for_authenticated_users(): void
    {
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        $blockedUrls = [
            '/api/storage/surat-pengantar-magang/generated/final.pdf',
            '/api/storage/surat-keterangan-aktif/generated/final.pdf',
            '/api/storage/proses-luar-negeri/generated/final.pdf',
            '/api/storage/scholarships/final.docx',
            '/api/storage/letter-document-artifacts/surat-permohonan-beasiswa/1/tendik_review/preview.pdf',
            '/api/storage/profiles/signatures/signature.png',
        ];

        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF');
        Storage::disk('public')->put('surat-keterangan-aktif/generated/final.pdf', '%PDF');
        Storage::disk('public')->put('proses-luar-negeri/generated/final.pdf', '%PDF');
        Storage::disk('public')->put('scholarships/final.docx', 'docx');
        Storage::disk('public')->put('letter-document-artifacts/surat-permohonan-beasiswa/1/tendik_review/preview.pdf', '%PDF');
        Storage::disk('public')->put('profiles/signatures/signature.png', 'png');

        foreach ($blockedUrls as $url) {
            $this->actingAs($student, 'sanctum')
                ->get($url)
                ->assertForbidden();
        }
    }

    public function test_owner_can_access_own_profile_photo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profiles/fotos/photo.jpg', 'img');
        [$student, $profile] = $this->completeMahasiswa();
        MahasiswaProfile::whereKey($profile->id)->update([
            'pas_foto_path' => Storage::url('profiles/fotos/photo.jpg'),
        ]);

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/profiles/fotos/photo.jpg')
            ->assertOk();
    }

    public function test_unrelated_student_cannot_access_another_students_profile_photo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profiles/fotos/photo.jpg', 'img');
        [, $profile] = $this->completeMahasiswa();
        MahasiswaProfile::whereKey($profile->id)->update([
            'pas_foto_path' => Storage::url('profiles/fotos/photo.jpg'),
        ]);
        [$otherStudent] = $this->completeMahasiswa();

        $this->actingAs($otherStudent, 'sanctum')
            ->get('/api/storage/profiles/fotos/photo.jpg')
            ->assertForbidden();
    }

    public function test_reviewer_roles_can_access_profile_photo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profiles/fotos/photo.jpg', 'img');
        [, $profile] = $this->completeMahasiswa();
        MahasiswaProfile::whereKey($profile->id)->update([
            'pas_foto_path' => Storage::url('profiles/fotos/photo.jpg'),
        ]);

        foreach ([$this->tendikPersuratan(), $this->akademik('sekprodi'), $this->primarySuperAdmin()] as $reviewer) {
            $this->actingAs($reviewer, 'sanctum')
                ->get('/api/storage/profiles/fotos/photo.jpg')
                ->assertOk();
        }
    }

    public function test_owner_mahasiswa_can_access_own_signature(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profiles/signatures/sig.png', 'png');
        [$student, $profile] = $this->completeMahasiswa();
        MahasiswaProfile::whereKey($profile->id)->update([
            'tanda_tangan_path' => Storage::url('profiles/signatures/sig.png'),
        ]);

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/profiles/signatures/sig.png')
            ->assertOk();
    }

    public function test_unrelated_student_cannot_access_another_students_signature(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profiles/signatures/sig.png', 'png');
        [, $profile] = $this->completeMahasiswa();
        MahasiswaProfile::whereKey($profile->id)->update([
            'tanda_tangan_path' => Storage::url('profiles/signatures/sig.png'),
        ]);
        [$otherStudent] = $this->completeMahasiswa();

        $this->actingAs($otherStudent, 'sanctum')
            ->get('/api/storage/profiles/signatures/sig.png')
            ->assertForbidden();
    }

    public function test_owner_staff_can_access_own_signature_and_others_cannot(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profiles/signatures/staff.png', 'png');
        $tendik = $this->tendikPersuratan();
        $tendik->forceFill(['signature_path' => Storage::url('profiles/signatures/staff.png')])->save();

        $this->actingAs($tendik, 'sanctum')
            ->get('/api/storage/profiles/signatures/staff.png')
            ->assertOk();

        $otherTendik = $this->tendikPersuratan();
        $this->actingAs($otherTendik, 'sanctum')
            ->get('/api/storage/profiles/signatures/staff.png')
            ->assertForbidden();
    }

    public function test_owner_mahasiswa_can_access_own_magang_proposal(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('surat-pengantar-magang/proposals/proposal.pdf', '%PDF');
        [$student] = $this->completeMahasiswa();
        $this->magangApplication($student, [
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/proposal.pdf'),
        ]);

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/surat-pengantar-magang/proposals/proposal.pdf')
            ->assertOk();
    }

    public function test_unrelated_student_cannot_access_another_students_magang_proposal(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('surat-pengantar-magang/proposals/proposal.pdf', '%PDF');
        [$owner] = $this->completeMahasiswa();
        $this->magangApplication($owner, [
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/proposal.pdf'),
        ]);
        [$otherStudent] = $this->completeMahasiswa();

        $this->actingAs($otherStudent, 'sanctum')
            ->get('/api/storage/surat-pengantar-magang/proposals/proposal.pdf')
            ->assertForbidden();
    }

    public function test_assigned_tendik_can_access_magang_proposal_and_unassigned_tendik_cannot(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('surat-pengantar-magang/proposals/proposal.pdf', '%PDF');
        [$student] = $this->completeMahasiswa();
        $this->magangApplication($student, [
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/proposal.pdf'),
        ]);

        $assignedTendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $unassignedTendik = $this->tendikPersuratan([]);

        $this->actingAs($assignedTendik, 'sanctum')
            ->get('/api/storage/surat-pengantar-magang/proposals/proposal.pdf')
            ->assertOk();

        $this->actingAs($unassignedTendik, 'sanctum')
            ->get('/api/storage/surat-pengantar-magang/proposals/proposal.pdf')
            ->assertForbidden();
    }

    public function test_scoped_akademik_can_access_magang_proposal_and_wrong_scope_cannot(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('surat-pengantar-magang/proposals/proposal.pdf', '%PDF');
        [$student] = $this->completeMahasiswa();
        $this->magangApplication($student, [
            'proposal_kegiatan_magang_path' => Storage::url('surat-pengantar-magang/proposals/proposal.pdf'),
        ]);

        $scopedAkademik = $this->akademik('sekprodi');
        $otherDepartment = $this->department(['code' => 'OTHER', 'name' => 'Other Department']);
        $otherProgram = $this->studyProgram($otherDepartment, ['code' => 'OTH', 'name' => 'Other Program']);
        $wrongAkademik = $this->akademik('sekprodi', [
            'study_program_id' => $otherProgram->id,
            'department_id' => $otherDepartment->id,
        ]);

        $this->actingAs($scopedAkademik, 'sanctum')
            ->get('/api/storage/surat-pengantar-magang/proposals/proposal.pdf')
            ->assertOk();

        $this->actingAs($wrongAkademik, 'sanctum')
            ->get('/api/storage/surat-pengantar-magang/proposals/proposal.pdf')
            ->assertForbidden();
    }

    public function test_owner_mahasiswa_can_access_own_scholarship_attachment(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('scholarships/transcripts/file.pdf', '%PDF');
        [$student] = $this->completeMahasiswa();
        $this->scholarshipApplication($student, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/file.pdf'),
        ]);

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/scholarships/transcripts/file.pdf')
            ->assertOk();
    }

    public function test_unrelated_student_cannot_access_another_students_scholarship_attachment(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('scholarships/transcripts/file.pdf', '%PDF');
        [$owner] = $this->completeMahasiswa();
        $this->scholarshipApplication($owner, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/file.pdf'),
        ]);
        [$otherStudent] = $this->completeMahasiswa();

        $this->actingAs($otherStudent, 'sanctum')
            ->get('/api/storage/scholarships/transcripts/file.pdf')
            ->assertForbidden();
    }

    public function test_assigned_tendik_can_access_scholarship_attachment_and_unassigned_tendik_cannot(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('scholarships/slips/slip.pdf', '%PDF');
        [$student] = $this->completeMahasiswa();
        $this->scholarshipApplication($student, [
            'slip_gaji_ayah_path' => Storage::url('scholarships/slips/slip.pdf'),
        ]);

        $assignedTendik = $this->tendikPersuratan([ScholarshipApplication::LETTER_TYPE]);
        $unassignedTendik = $this->tendikPersuratan([]);

        $this->actingAs($assignedTendik, 'sanctum')
            ->get('/api/storage/scholarships/slips/slip.pdf')
            ->assertOk();

        $this->actingAs($unassignedTendik, 'sanctum')
            ->get('/api/storage/scholarships/slips/slip.pdf')
            ->assertForbidden();
    }

    public function test_scoped_akademik_can_access_scholarship_attachment_and_wrong_scope_cannot(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('scholarships/transcripts/file.pdf', '%PDF');
        [$student] = $this->completeMahasiswa();
        $this->scholarshipApplication($student, [
            'transkrip_nilai_path' => Storage::url('scholarships/transcripts/file.pdf'),
        ]);

        $scopedAkademik = $this->akademik('sekprodi');
        $otherDepartment = $this->department(['code' => 'OTHER2', 'name' => 'Other Department 2']);
        $otherProgram = $this->studyProgram($otherDepartment, ['code' => 'OT2', 'name' => 'Other Program 2']);
        $wrongAkademik = $this->akademik('sekprodi', [
            'study_program_id' => $otherProgram->id,
            'department_id' => $otherDepartment->id,
        ]);

        $this->actingAs($scopedAkademik, 'sanctum')
            ->get('/api/storage/scholarships/transcripts/file.pdf')
            ->assertOk();

        $this->actingAs($wrongAkademik, 'sanctum')
            ->get('/api/storage/scholarships/transcripts/file.pdf')
            ->assertForbidden();
    }

    public function test_unknown_folders_return_403_without_existence_leak(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('other-folder/file.txt', 'data');
        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/other-folder/file.txt')
            ->assertForbidden();

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/custom-uploads/missing.pdf')
            ->assertForbidden();

        $this->actingAs($student, 'sanctum')
            ->get('/api/storage/scholarships/ktm/card.png')
            ->assertForbidden();
    }

    public function test_missing_authorized_file_returns_404_after_authorization(): void
    {
        Storage::fake('public');
        $tendik = $this->tendikPersuratan();

        $this->actingAs($tendik, 'sanctum')
            ->get('/api/storage/profiles/fotos/missing.jpg')
            ->assertNotFound();
    }

    public function test_path_traversal_attempts_return_403(): void
    {
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        foreach ([
            '/api/storage/profiles/fotos/%2e%2e/.env',
            '/api/storage/profiles/fotos/%252e%252e/.env',
        ] as $url) {
            $this->actingAs($student, 'sanctum')
                ->get($url)
                ->assertForbidden();
        }
    }

    public function test_public_storage_route_cannot_bypass_protected_storage(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profiles/fotos/photo.jpg', 'img');
        Storage::disk('public')->put('profiles/signatures/signature.png', 'png');
        Storage::disk('public')->put('surat-pengantar-magang/proposals/proposal.pdf', '%PDF');
        Storage::disk('public')->put('scholarships/transcripts/file.pdf', '%PDF');
        Storage::disk('public')->put('surat-pengantar-magang/generated/final.pdf', '%PDF');
        Storage::disk('public')->put('letter-document-artifacts/surat-permohonan-beasiswa/1/tendik_review/preview.pdf', '%PDF');

        foreach ([
            '/storage/profiles/fotos/photo.jpg',
            '/storage/profiles/signatures/signature.png',
            '/storage/surat-pengantar-magang/proposals/proposal.pdf',
            '/storage/scholarships/transcripts/file.pdf',
            '/storage/surat-pengantar-magang/generated/final.pdf',
            '/storage/letter-document-artifacts/surat-permohonan-beasiswa/1/tendik_review/preview.pdf',
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    }
}
