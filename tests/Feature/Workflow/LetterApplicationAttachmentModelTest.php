<?php

namespace Tests\Feature\Workflow;

use App\Models\LetterApplicationAttachment;
use App\Models\ScholarshipApplication;
use App\Models\SuratPengantarMagangApplication;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LetterApplicationAttachmentModelTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_schema_contains_additive_attachment_registry_contract(): void
    {
        $this->assertTrue(Schema::hasTable('letter_application_attachments'));
        $this->assertTrue(Schema::hasColumns('letter_application_attachments', [
            'id',
            'letter_type',
            'application_id',
            'document_key',
            'original_filename',
            'mime_type',
            'size_bytes',
            'storage_disk',
            'storage_path',
            'checksum_sha256',
            'uploaded_by',
            'created_at',
            'updated_at',
        ]));

        $indexNames = collect(DB::select("PRAGMA index_list('letter_application_attachments')"))
            ->pluck('name')
            ->all();

        $this->assertContains('laa_letter_app_key_unique', $indexNames);
        $this->assertContains('laa_letter_app_idx', $indexNames);
    }

    public function test_same_letter_application_and_document_key_must_be_unique(): void
    {
        $application = $this->scholarshipApplication();
        $this->attachment(ScholarshipApplication::LETTER_TYPE, $application->id);

        $this->expectException(QueryException::class);

        $this->attachment(ScholarshipApplication::LETTER_TYPE, $application->id);
    }

    public function test_same_document_key_is_allowed_across_applications_and_letter_types(): void
    {
        $first = $this->scholarshipApplication();
        $second = $this->scholarshipApplication();

        $this->attachment(ScholarshipApplication::LETTER_TYPE, $first->id);
        $this->attachment(ScholarshipApplication::LETTER_TYPE, $second->id);
        $this->attachment(SuratPengantarMagangApplication::LETTER_TYPE, $first->id);

        $this->assertDatabaseCount('letter_application_attachments', 3);
    }

    private function attachment(string $letterType, int $applicationId): LetterApplicationAttachment
    {
        return LetterApplicationAttachment::create([
            'letter_type' => $letterType,
            'application_id' => $applicationId,
            'document_key' => 'proposal',
            'original_filename' => 'proposal.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 123,
            'storage_disk' => 'local',
            'storage_path' => "letter-application-attachments/{$letterType}/proposal/{$applicationId}.pdf",
            'checksum_sha256' => str_repeat('a', 64),
            'uploaded_by' => null,
        ]);
    }
}
