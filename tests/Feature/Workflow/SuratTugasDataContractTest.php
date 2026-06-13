<?php

namespace Tests\Feature\Workflow;

use App\Models\SuratTugasApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuratTugasDataContractTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_letter_type_constant(): void
    {
        $this->assertSame('surat-tugas', SuratTugasApplication::LETTER_TYPE);
    }

    public function test_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('surat_tugas_applications'));

        foreach ([
            'user_id', 'mahasiswa_profile_id',
            'nama_perusahaan', 'kegiatan', 'posisi', 'dosen_pembimbing_dpa',
            'tgl_mulai', 'tgl_selesai',
            'nomor_surat_tugas', 'assigned_to', 'status', 'revision_note', 'rejection_reason',
            'submitted_at',
            'tendik_approved_at', 'tendik_approved_by',
            'kaprodi_approved_at', 'kaprodi_approved_by',
            'kadep_approved_at', 'kadep_approved_by',
            'revised_at', 'revised_by', 'rejected_at', 'rejected_by',
            'student_reviewed_at', 'completed_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('surat_tugas_applications', $column),
                "surat_tugas_applications is missing column {$column}.",
            );
        }

        foreach (['proposal_kegiatan_magang_path', 'surat_pengantar_magang_path'] as $retiredColumn) {
            $this->assertFalse(
                Schema::hasColumn('surat_tugas_applications', $retiredColumn),
                "surat_tugas_applications must not retain retired column {$retiredColumn}.",
            );
        }
    }

    public function test_no_public_generated_path_columns(): void
    {
        // Artifact-ledger pattern: the table must not carry public/generated paths.
        foreach (['generated_pdf_path', 'generated_docx_path', 'docx_url'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('surat_tugas_applications', $forbidden),
                "surat_tugas_applications must not have {$forbidden}.",
            );
        }
    }

    public function test_model_fillable_and_casts(): void
    {
        $model = new SuratTugasApplication();
        $fillable = $model->getFillable();

        foreach ([
            'nama_perusahaan', 'kegiatan', 'posisi', 'dosen_pembimbing_dpa',
            'tgl_mulai', 'tgl_selesai', 'nomor_surat_tugas', 'status',
        ] as $field) {
            $this->assertContains($field, $fillable, "{$field} should be fillable.");
        }

        foreach (['proposal_kegiatan_magang_path', 'surat_pengantar_magang_path'] as $field) {
            $this->assertNotContains($field, $fillable, "{$field} should not be fillable.");
        }

        $casts = $model->getCasts();
        $this->assertSame('date', $casts['tgl_mulai'] ?? null);
        $this->assertSame('date', $casts['tgl_selesai'] ?? null);
        $this->assertSame('datetime', $casts['submitted_at'] ?? null);
        $this->assertSame('datetime', $casts['completed_at'] ?? null);
    }

    public function test_persists_and_relations_resolve(): void
    {
        $application = $this->suratTugasApplication(null, [
            'nomor_surat_tugas' => 'ST/001/2026',
            'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
        ]);

        $this->assertDatabaseHas('surat_tugas_applications', [
            'id' => $application->id,
            'nomor_surat_tugas' => 'ST/001/2026',
            'status' => SuratTugasApplication::STATUS_APPROVED_TENDIK,
        ]);

        $fresh = $application->fresh(['user', 'mahasiswaProfile']);
        $this->assertNotNull($fresh->user);
        $this->assertSame('2026-06-01', $fresh->tgl_mulai->format('Y-m-d'));
    }
}
