<?php

namespace Tests\Feature\Workflow;

use App\Models\ProsesLuarNegeriApplication;
use App\Models\ScholarshipApplication;
use App\Models\SuratKeteranganAktifApplication;
use App\Models\SuratPengantarMagangApplication;
use App\Models\SuratTugasApplication;
use App\Support\LetterAttachmentDefinitionRegistry;
use Tests\TestCase;

class LetterAttachmentDefinitionRegistryTest extends TestCase
{
    public function test_active_and_empty_letter_definitions_are_explicit(): void
    {
        $this->assertCount(3, $this->documents(ScholarshipApplication::LETTER_TYPE));
        $this->assertCount(1, $this->documents(SuratPengantarMagangApplication::LETTER_TYPE));
        $this->assertCount(2, $this->documents(SuratTugasApplication::LETTER_TYPE));
        $this->assertSame([], $this->documents(SuratKeteranganAktifApplication::LETTER_TYPE));
        $this->assertSame([], $this->documents(ProsesLuarNegeriApplication::LETTER_TYPE));
    }

    public function test_dormant_ktm_is_excluded(): void
    {
        $this->assertNull(
            LetterAttachmentDefinitionRegistry::document(ScholarshipApplication::LETTER_TYPE, 'ktm')
        );
        $this->assertStringNotContainsString('ktm_path', json_encode(LetterAttachmentDefinitionRegistry::all()));
    }

    public function test_all_active_definitions_target_private_pdf_storage(): void
    {
        foreach (LetterAttachmentDefinitionRegistry::all() as $letterType => $letter) {
            foreach ($letter['documents'] as $document) {
                $this->assertSame(['application/pdf'], $document['mime_types']);
                $this->assertSame('local', $document['storage_disk']);
                $this->assertStringStartsWith(
                    "letter-application-attachments/{$letterType}/",
                    $document['storage_prefix'],
                );
                $this->assertTrue($document['preview']);
            }
        }
    }

    public function test_legacy_alias_resolves_to_canonical_beasiswa_definition(): void
    {
        $this->assertNotNull(LetterAttachmentDefinitionRegistry::forLetter('beasiswa'));
        $this->assertNotNull(LetterAttachmentDefinitionRegistry::document('beasiswa', 'transkrip_nilai'));
    }

    /**
     * @return array<string, mixed>
     */
    private function documents(string $letterType): array
    {
        return LetterAttachmentDefinitionRegistry::forLetter($letterType)['documents'];
    }
}
