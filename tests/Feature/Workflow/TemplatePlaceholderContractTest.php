<?php

namespace Tests\Feature\Workflow;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalTemplatePlaceholderContracts;
use Tests\Support\TemplatePlaceholderAssertions;
use Tests\TestCase;

class TemplatePlaceholderContractTest extends TestCase
{
    public function test_placeholder_extractor_extracts_normal_placeholders_from_docx(): void
    {
        $path = $this->docxWithPlaceholders(['nomor_surat_rekomendasi', 'nama']);

        try {
            $this->assertSame(
                ['nama', 'nomor_surat_rekomendasi'],
                TemplatePlaceholderAssertions::extractPlaceholdersFromDocx($path),
            );
        } finally {
            @unlink($path);
        }
    }

    #[DataProvider('malformedPlaceholderXmlProvider')]
    public function test_placeholder_syntax_lint_rejects_malformed_examples(string $xml, string $expectedErrorFragment): void
    {
        $analysis = TemplatePlaceholderAssertions::analyzeXml($xml);
        $errors = implode("\n", $analysis['syntax_errors']);

        $this->assertNotSame('', $errors);
        $this->assertStringContainsString($expectedErrorFragment, $errors);
    }

    public static function malformedPlaceholderXmlProvider(): array
    {
        return [
            'unclosed placeholder' => ['<w:t>${nama</w:t>', 'Unclosed placeholder'],
            'split dollar and brace' => ['<w:t>$</w:t><w:t>{nama}</w:t>', 'Split-run placeholder'],
            'split placeholder name' => ['<w:t>${nama</w:t><w:t>_kadep}</w:t>', 'Split-run placeholder'],
            'whitespace in name' => ['<w:t>${nama lengkap}</w:t>', 'contains whitespace'],
            'uppercase name' => ['<w:t>${Nama}</w:t>', 'not lower_snake_case'],
            'hyphenated name' => ['<w:t>${nama-lengkap}</w:t>', 'not lower_snake_case'],
        ];
    }

    public function test_final_contracts_define_letter_specific_number_placeholders_and_exclude_generic_nomor_surat(): void
    {
        $expectedNumberPlaceholders = [
            'surat-permohonan-beasiswa' => ['nomor_surat_rekomendasi'],
            'surat-pengantar-magang' => ['nomor_surat_pengantar'],
            'surat-keterangan-aktif' => ['nomor_surat_aktif'],
            'proses-luar-negeri' => ['nomor_surat_luar_negeri'],
            'surat-tugas' => ['nomor_surat_tugas'],
        ];

        foreach (FinalTemplatePlaceholderContracts::all() as $key => $contract) {
            foreach ($expectedNumberPlaceholders[$key] as $placeholder) {
                $this->assertContains($placeholder, $contract['placeholders'], "{$key} is missing {$placeholder}.");
            }

            $this->assertNotContains('nomor_surat', $contract['placeholders'], "{$key} must not accept generic nomor_surat as final contract.");
        }
    }

    public function test_final_beasiswa_contract_rejects_generic_nomor_surat_but_allows_rekomendasi_placeholder(): void
    {
        $valid = TemplatePlaceholderAssertions::contractViolations(
            ['nomor_surat_rekomendasi'],
            FinalTemplatePlaceholderContracts::BEASISWA,
            ['nomor_surat_rekomendasi'],
            FinalTemplatePlaceholderContracts::FORBIDDEN_FINAL_PLACEHOLDERS,
        );
        $this->assertSame(['unknown' => [], 'missing' => [], 'forbidden' => []], $valid);

        $legacy = TemplatePlaceholderAssertions::contractViolations(
            ['nomor_surat'],
            FinalTemplatePlaceholderContracts::BEASISWA,
            [],
            FinalTemplatePlaceholderContracts::FORBIDDEN_FINAL_PLACEHOLDERS,
        );
        $this->assertContains('nomor_surat', $legacy['unknown']);
        $this->assertContains('nomor_surat', $legacy['forbidden']);
    }

    public function test_generic_image_placeholders_are_rejected_from_final_contracts(): void
    {
        foreach (FinalTemplatePlaceholderContracts::all() as $key => $contract) {
            $violations = TemplatePlaceholderAssertions::contractViolations(
                ['ttd_kadep', 'paraf', 'stempel_kadep'],
                $contract['placeholders'],
                [],
                FinalTemplatePlaceholderContracts::FORBIDDEN_FINAL_PLACEHOLDERS,
            );

            $this->assertSame(['paraf', 'stempel_kadep', 'ttd_kadep'], $violations['forbidden'], "{$key} must reject generic image placeholders.");
        }
    }

    public function test_contract_comparison_reports_unknown_placeholders(): void
    {
        $violations = TemplatePlaceholderAssertions::contractViolations(
            ['nama', 'placeholder_baru'],
            FinalTemplatePlaceholderContracts::SKA,
            [],
            FinalTemplatePlaceholderContracts::FORBIDDEN_FINAL_PLACEHOLDERS,
        );

        $this->assertSame(['placeholder_baru'], $violations['unknown']);
    }

    public function test_synthetic_final_templates_render_without_unresolved_placeholders(): void
    {
        foreach (FinalTemplatePlaceholderContracts::all() as $key => $contract) {
            $path = $this->docxWithPlaceholders($contract['placeholders']);

            try {
                $analysis = TemplatePlaceholderAssertions::analyzeDocx($path);
                $this->assertSame([], $analysis['syntax_errors'], "{$key} synthetic DOCX should have valid placeholder syntax.");

                $violations = TemplatePlaceholderAssertions::contractViolations(
                    $analysis['placeholders'],
                    $contract['placeholders'],
                    $contract['placeholders'],
                    FinalTemplatePlaceholderContracts::FORBIDDEN_FINAL_PLACEHOLDERS,
                );
                $this->assertSame(['unknown' => [], 'missing' => [], 'forbidden' => []], $violations, "{$key} synthetic DOCX should match final contract.");

                $xml = TemplatePlaceholderAssertions::renderDocxToXml($path, $this->sampleValues($contract['placeholders']));
                $this->assertSame([], TemplatePlaceholderAssertions::unresolvedPlaceholdersInXml($xml), "{$key} rendered DOCX should not leave placeholders unresolved.");
                $this->assertSharedRenderingValues($xml, $contract['placeholders']);
            } finally {
                @unlink($path);
            }
        }
    }

    public function test_active_cached_beasiswa_template_uses_final_nomor_surat_rekomendasi_contract(): void
    {
        $cachePath = config('surat.template_beasiswa_cache_path');
        if (!is_string($cachePath) || !is_file($cachePath)) {
            $this->markTestSkipped('Beasiswa template cache is not present in this environment.');
        }

        $analysis = TemplatePlaceholderAssertions::analyzeDocx($cachePath);
        $this->assertSame([], $analysis['syntax_errors']);

        $placeholders = $analysis['placeholders'];
        $this->assertContains('nomor_surat_rekomendasi', $placeholders);
        $this->assertNotContains('nomor_surat', $placeholders);

        $violations = TemplatePlaceholderAssertions::contractViolations(
            $placeholders,
            FinalTemplatePlaceholderContracts::BEASISWA,
            ['nomor_surat_rekomendasi'],
            FinalTemplatePlaceholderContracts::FORBIDDEN_FINAL_PLACEHOLDERS,
        );

        $this->assertSame(['unknown' => [], 'missing' => [], 'forbidden' => []], $violations);

        $xml = TemplatePlaceholderAssertions::renderDocxToXml($cachePath, $this->sampleValues($placeholders));
        $this->assertSame([], TemplatePlaceholderAssertions::unresolvedPlaceholdersInXml($xml));
    }

    /**
     * @param list<string> $placeholders
     */
    private function docxWithPlaceholders(array $placeholders): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        foreach (array_chunk($placeholders, 8) as $chunk) {
            $section->addText(implode(' ', array_map(
                fn (string $placeholder): string => '${' . $placeholder . '}',
                $chunk,
            )));
        }

        $path = tempnam(sys_get_temp_dir(), 'template_contract_') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    /**
     * @param list<string> $placeholders
     * @return array<string, string>
     */
    private function sampleValues(array $placeholders): array
    {
        $values = [];
        foreach ($placeholders as $placeholder) {
            $values[$placeholder] = "value_{$placeholder}";
        }

        $values['fakultas'] = 'Sekolah Vokasi';
        $values['jabatan_kadep'] = 'Ketua Departemen';
        $values['departemen'] = 'Teknik Elektro dan Informatika';

        return $values;
    }

    /**
     * @param list<string> $placeholders
     */
    private function assertSharedRenderingValues(string $xml, array $placeholders): void
    {
        if (in_array('fakultas', $placeholders, true)) {
            $this->assertStringContainsString('Sekolah Vokasi', $xml);
            $this->assertStringNotContainsString('Sekolah Vokasi UGM', $xml);
        }

        if (in_array('jabatan_kadep', $placeholders, true)) {
            $this->assertStringContainsString('Ketua Departemen', $xml);
        }

        if (in_array('departemen', $placeholders, true)) {
            $this->assertStringContainsString('Teknik Elektro dan Informatika', $xml);
            $this->assertStringNotContainsString('Departemen Departemen', $xml);
        }
    }
}
