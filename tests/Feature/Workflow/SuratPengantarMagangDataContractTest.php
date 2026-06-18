<?php

namespace Tests\Feature\Workflow;

use App\Models\SuratPengantarMagangApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuratPengantarMagangDataContractTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_schema_and_model_expose_nullable_final_contract_fields_and_date_casts(): void
    {
        $fields = [
            'nomor_surat_pengantar',
            'nomor_surat_tugas',
            'jabatan_penerima',
            'alamat_jalan',
            'alamat_kelurahan',
            'alamat_kecamatan',
            'alamat_kota_kabupaten',
            'alamat_provinsi',
            'kode_pos',
            'tgl_mulai',
            'tgl_selesai',
        ];

        $this->assertTrue(Schema::hasColumns('surat_pengantar_magang_applications', $fields));

        $model = new SuratPengantarMagangApplication();
        foreach ($fields as $field) {
            $this->assertContains($field, $model->getFillable());
        }

        $application = $this->magangApplication(null, [
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
        ])->fresh();

        $this->assertSame('2026-06-01', $application->tgl_mulai->format('Y-m-d'));
        $this->assertSame('2026-08-31', $application->tgl_selesai->format('Y-m-d'));
    }

    public function test_mahasiswa_can_save_and_submit_additive_structured_fields(): void
    {
        Storage::fake('public');
        $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();

        $draft = $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', array_merge(
                $this->legacyDraftPayload(),
                $this->structuredDraftPayload(),
                ['proposal_kegiatan_magang' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf')],
            ))
            ->assertOk()
            ->assertJsonPath('application.jabatan_penerima', 'Direktur Operasional');

        $applicationId = $draft->json('application.id');

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $applicationId,
            'jabatan_penerima' => 'Direktur Operasional',
            'alamat_jalan' => 'Jl. Test No. 1',
            'alamat_kelurahan' => 'Caturtunggal',
            'alamat_kecamatan' => 'Depok',
            'alamat_kota_kabupaten' => 'Sleman',
            'alamat_provinsi' => 'Daerah Istimewa Yogyakarta',
            'kode_pos' => '55281',
        ]);

        $application = SuratPengantarMagangApplication::findOrFail($applicationId);
        $this->assertSame('2026-06-01', $application->tgl_mulai->format('Y-m-d'));
        $this->assertSame('2026-08-31', $application->tgl_selesai->format('Y-m-d'));

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertOk()
            ->assertJsonPath('application.status', SuratPengantarMagangApplication::STATUS_SUBMITTED)
            ->assertJsonPath('application.alamat_kelurahan', 'Caturtunggal');
    }

    public function test_legacy_mahasiswa_draft_remains_storable_but_cannot_submit_without_final_contract_fields(): void
    {
        Storage::fake('public');
        $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        [$student] = $this->completeMahasiswa();

        $draft = $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', array_merge(
                $this->legacyDraftPayload(),
                ['proposal_kegiatan_magang' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf')],
            ))
            ->assertOk();

        $applicationId = $draft->json('application.id');

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/mahasiswa/surat-pengantar-magang/submit')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Dokumen pratinjau pengajuan belum dapat dibuat. Silakan coba lagi.');

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $applicationId,
            'status' => SuratPengantarMagangApplication::STATUS_DRAFT,
            'jabatan_penerima' => null,
            'alamat_jalan' => null,
            'tgl_mulai' => null,
            'tgl_selesai' => null,
        ]);
    }

    public function test_structured_date_range_rejects_an_end_date_before_the_start_date(): void
    {
        Storage::fake('public');
        [$student] = $this->completeMahasiswa();

        $this->actingAs($student, 'sanctum')
            ->post('/api/mahasiswa/surat-pengantar-magang/draft', array_merge(
                $this->legacyDraftPayload(),
                $this->structuredDraftPayload([
                    'tgl_mulai' => '2026-08-31',
                    'tgl_selesai' => '2026-06-01',
                ]),
                ['proposal_kegiatan_magang' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf')],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tgl_selesai']);
    }

    public function test_tendik_can_approve_with_two_explicit_final_numbers(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication();

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat_pengantar' => 'MAG/PENGANTAR/001/2026',
                'nomor_surat_tugas' => 'MAG/TUGAS/001/2026',
            ])
            ->assertOk()
            ->assertJsonPath('application.nomor_surat_pengantar', 'MAG/PENGANTAR/001/2026')
            ->assertJsonPath('application.nomor_surat_tugas', 'MAG/TUGAS/001/2026')
            ->assertJsonPath('application.nomor_surat', 'MAG/PENGANTAR/001/2026');

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK,
            'nomor_surat' => 'MAG/PENGANTAR/001/2026',
            'nomor_surat_pengantar' => 'MAG/PENGANTAR/001/2026',
            'nomor_surat_tugas' => 'MAG/TUGAS/001/2026',
        ]);
    }

    public function test_tendik_legacy_number_payload_is_rejected_without_duplicating_final_numbers(): void
    {
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication();

        // S1 (Magang standalone): only nomor_surat_pengantar is required now.
        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat' => 'MAG/LEGACY/001/2026',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nomor_surat_pengantar'])
            ->assertJsonMissingValidationErrors(['nomor_surat_tugas']);

        $this->assertDatabaseHas('surat_pengantar_magang_applications', [
            'id' => $application->id,
            'status' => SuratPengantarMagangApplication::STATUS_SUBMITTED,
            'nomor_surat' => null,
            'nomor_surat_pengantar' => null,
            'nomor_surat_tugas' => null,
        ]);
    }

    public function test_tendik_can_approve_with_pengantar_number_only_and_tugas_is_optional(): void
    {
        // S1 (Magang standalone): Surat Tugas split out — the Magang approval no
        // longer requires nomor_surat_tugas. Pengantar-only is a valid approval.
        $tendik = $this->tendikPersuratan([SuratPengantarMagangApplication::LETTER_TYPE]);
        $application = $this->magangApplication();

        $this->mockMagangPreviewGenerationAlwaysReady();

        $this->actingAs($tendik, 'sanctum')
            ->patchJson("/api/tendik/surat-pengantar-magang/{$application->id}/approve", [
                'nomor_surat_pengantar' => 'MAG/PENGANTAR/ONLY/2026',
            ])
            ->assertOk()
            ->assertJsonPath('application.nomor_surat_pengantar', 'MAG/PENGANTAR/ONLY/2026');

        $fresh = $application->fresh();
        $this->assertSame(SuratPengantarMagangApplication::STATUS_APPROVED_TENDIK, $fresh->status);
        $this->assertNull($fresh->nomor_surat_tugas);
    }

    private function legacyDraftPayload(array $overrides = []): array
    {
        return array_merge([
            'nama_penerima' => 'Direktur Operasional',
            'nama_perusahaan' => 'PT Test',
            'alamat_perusahaan' => 'Jl. Test No. 1, Sleman',
            'peran' => 'Software Engineer Intern',
            'rentang_tanggal' => '1 Juni 2026 - 31 Agustus 2026',
            'dosen_pembimbing_dpa' => 'Dr. Test',
        ], $overrides);
    }

    private function structuredDraftPayload(array $overrides = []): array
    {
        return array_merge([
            'jabatan_penerima' => 'Direktur Operasional',
            'alamat_jalan' => 'Jl. Test No. 1',
            'alamat_kelurahan' => 'Caturtunggal',
            'alamat_kecamatan' => 'Depok',
            'alamat_kota_kabupaten' => 'Sleman',
            'alamat_provinsi' => 'Daerah Istimewa Yogyakarta',
            'kode_pos' => '55281',
            'tgl_mulai' => '2026-06-01',
            'tgl_selesai' => '2026-08-31',
        ], $overrides);
    }
}
