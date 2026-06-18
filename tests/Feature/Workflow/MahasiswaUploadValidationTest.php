<?php

namespace Tests\Feature\Workflow;

use App\Models\ScholarshipApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MahasiswaUploadValidationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    private function beasiswaDraftFor(User $student): ScholarshipApplication
    {
        return ScholarshipApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $student->mahasiswaProfile?->id,
            'scholarship_name' => 'Beasiswa Test',
            'status' => ScholarshipApplication::STATUS_DRAFT,
        ]);
    }

    private function step3BasePayload(): array
    {
        return [
            'scholarship_name' => 'Beasiswa Test',
            'current_semester' => 5,
            'family_dependents' => 3,
            'gpa_last_semesters' => 3.75,
            'ipk' => 3.8,
            'sks_last_semesters' => 22,
            'total_sks_passed' => 110,
            'total_sks_required' => 144,
            'on_leave' => 'Belum',
            'thesis_status' => 'Belum',
            'has_scholarship_history' => 'Belum',
        ];
    }

    private function magangDraftBasePayload(): array
    {
        return [
            'nama_penerima' => 'HR Department',
            'nama_perusahaan' => 'PT Test',
            'alamat_perusahaan' => 'Jl. Test No. 1',
            'peran' => 'Software Engineer Intern',
            'rentang_tanggal' => '1 Juni 2026 - 31 Agustus 2026',
            'dosen_pembimbing_dpa' => 'Dr. Test',
        ];
    }

    public function test_beasiswa_step3_accepts_pdf_for_transkrip_nilai(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', array_merge($this->step3BasePayload(), [
                'transkrip_nilai' => UploadedFile::fake()->create('transkrip.pdf', 100, 'application/pdf'),
            ]))
            ->assertOk();
    }

    public function test_beasiswa_step3_accepts_pdf_for_slip_gaji_ayah(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', array_merge($this->step3BasePayload(), [
                'slip_gaji_ayah' => UploadedFile::fake()->create('slip-ayah.pdf', 100, 'application/pdf'),
            ]))
            ->assertOk();
    }

    public function test_beasiswa_step3_accepts_pdf_for_slip_gaji_ibu(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', array_merge($this->step3BasePayload(), [
                'slip_gaji_ibu' => UploadedFile::fake()->create('slip-ibu.pdf', 100, 'application/pdf'),
            ]))
            ->assertOk();
    }

    public function test_beasiswa_step3_rejects_jpg_for_slip_gaji_ayah(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', array_merge($this->step3BasePayload(), [
                'slip_gaji_ayah' => UploadedFile::fake()->image('slip-ayah.jpg')->size(100),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('slip_gaji_ayah');
    }

    public function test_beasiswa_step3_rejects_jpg_for_slip_gaji_ibu(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', array_merge($this->step3BasePayload(), [
                'slip_gaji_ibu' => UploadedFile::fake()->image('slip-ibu.jpg')->size(100),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('slip_gaji_ibu');
    }

    public function test_beasiswa_step3_rejects_png_for_slip_gaji_ayah(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', array_merge($this->step3BasePayload(), [
                'slip_gaji_ayah' => UploadedFile::fake()->image('slip-ayah.png')->size(100),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('slip_gaji_ayah');
    }

    public function test_beasiswa_step3_rejects_png_for_slip_gaji_ibu(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', array_merge($this->step3BasePayload(), [
                'slip_gaji_ibu' => UploadedFile::fake()->image('slip-ibu.png')->size(100),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('slip_gaji_ibu');
    }

    public function test_beasiswa_step3_rejects_docx_for_transkrip_nilai(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', array_merge($this->step3BasePayload(), [
                'transkrip_nilai' => UploadedFile::fake()->create(
                    'transkrip.docx',
                    100,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('transkrip_nilai');
    }

    public function test_beasiswa_step3_rejects_docx_for_slip_gaji_ayah(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', array_merge($this->step3BasePayload(), [
                'slip_gaji_ayah' => UploadedFile::fake()->create(
                    'slip-ayah.docx',
                    100,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('slip_gaji_ayah');
    }

    public function test_beasiswa_step3_rejects_docx_for_slip_gaji_ibu(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', array_merge($this->step3BasePayload(), [
                'slip_gaji_ibu' => UploadedFile::fake()->create(
                    'slip-ibu.docx',
                    100,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('slip_gaji_ibu');
    }

    public function test_beasiswa_step3_allows_missing_optional_file_fields(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $this->beasiswaDraftFor($student);

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/scholarship/step-3', $this->step3BasePayload())
            ->assertOk();
    }

    public function test_magang_draft_accepts_pdf_for_proposal_kegiatan_magang(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', array_merge($this->magangDraftBasePayload(), [
                'proposal_kegiatan_magang' => UploadedFile::fake()->create(
                    'proposal.pdf',
                    100,
                    'application/pdf'
                ),
            ]))
            ->assertOk();
    }

    public function test_magang_draft_rejects_jpg_for_proposal_kegiatan_magang(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', array_merge($this->magangDraftBasePayload(), [
                'proposal_kegiatan_magang' => UploadedFile::fake()->image('proposal.jpg')->size(100),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('proposal_kegiatan_magang');
    }

    public function test_magang_draft_rejects_png_for_proposal_kegiatan_magang(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', array_merge($this->magangDraftBasePayload(), [
                'proposal_kegiatan_magang' => UploadedFile::fake()->image('proposal.png')->size(100),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('proposal_kegiatan_magang');
    }

    public function test_magang_draft_rejects_docx_for_proposal_kegiatan_magang(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', array_merge($this->magangDraftBasePayload(), [
                'proposal_kegiatan_magang' => UploadedFile::fake()->create(
                    'proposal.docx',
                    100,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('proposal_kegiatan_magang');
    }

    public function test_magang_draft_rejects_zip_for_proposal_kegiatan_magang(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', array_merge($this->magangDraftBasePayload(), [
                'proposal_kegiatan_magang' => UploadedFile::fake()->create(
                    'proposal.zip',
                    100,
                    'application/zip'
                ),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('proposal_kegiatan_magang');
    }

    public function test_magang_draft_allows_missing_proposal_when_registry_row_already_saved(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();
        $application = SuratPengantarMagangApplication::create([
            'user_id' => $student->id,
            'mahasiswa_profile_id' => $student->mahasiswaProfile?->id,
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
        ]);
        $this->attachRegistryDocument(
            $application,
            SuratPengantarMagangApplication::LETTER_TYPE,
            'proposal',
            'existing.pdf',
        );

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', $this->magangDraftBasePayload())
            ->assertOk();
    }

    public function test_mahasiswa_profile_still_accepts_image_for_pas_foto(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('pas-foto.png', 600, 800)->size(64),
            ])
            ->assertOk();
    }

    public function test_mahasiswa_profile_still_accepts_image_for_tanda_tangan(): void
    {
        Storage::fake('public');

        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/profile', [
                'tanda_tangan' => UploadedFile::fake()->image('tanda-tangan.png', 200, 100)->size(64),
            ])
            ->assertOk();
    }

    public function test_tendik_profile_still_accepts_image_for_pas_foto(): void
    {
        Storage::fake('public');

        $tendik = $this->tendikPersuratan();

        $this->actingAs($tendik, 'sanctum')
            ->post('/api/profile', [
                'pas_foto' => UploadedFile::fake()->image('pas-foto.png', 600, 800)->size(64),
            ])
            ->assertOk();
    }

    public function test_akademik_profile_still_accepts_image_for_tanda_tangan(): void
    {
        Storage::fake('public');

        $akademik = $this->akademik('kaprodi');

        $this->actingAs($akademik, 'sanctum')
            ->post('/api/profile', [
                'tanda_tangan' => UploadedFile::fake()->image('tanda-tangan.png', 200, 100)->size(64),
            ])
            ->assertOk();
    }
}
