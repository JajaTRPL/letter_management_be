<?php

namespace Tests\Feature\Workflow;

use App\Services\AcademicSignatoryService;
use Tests\TestCase;

class AcademicSignatoryTitleFormatterTest extends TestCase
{
    public function test_academic_office_titles_are_semantically_composed_without_duplicate_unit_words(): void
    {
        $service = $this->app->make(AcademicSignatoryService::class);

        $cases = [
            ['kadep', 'Departemen Teknik Elektro dan Informatika', 'Ketua Departemen Teknik Elektro dan Informatika'],
            ['kadep', 'Teknik Elektro dan Informatika', 'Ketua Departemen Teknik Elektro dan Informatika'],
            ['sekdep', 'Departemen Teknik Elektro dan Informatika', 'Sekretaris Departemen Teknik Elektro dan Informatika'],
            ['kaprodi', 'Teknologi Rekayasa Perangkat Lunak', 'Ketua Program Studi Teknologi Rekayasa Perangkat Lunak'],
            ['kaprodi', 'Program Studi Teknologi Rekayasa Perangkat Lunak', 'Ketua Program Studi Teknologi Rekayasa Perangkat Lunak'],
            ['sekprodi', 'Program Studi Teknologi Rekayasa Perangkat Lunak', 'Sekretaris Program Studi Teknologi Rekayasa Perangkat Lunak'],
            ['kadep', 'Ketua Departemen Teknik Elektro dan Informatika', 'Ketua Departemen Teknik Elektro dan Informatika'],
            ['kadep', 'Ketua Ketua Departemen Teknik Elektro dan Informatika', 'Ketua Departemen Teknik Elektro dan Informatika'],
            ['kadep', 'Departemen Departemen Teknik Elektro dan Informatika', 'Ketua Departemen Teknik Elektro dan Informatika'],
            ['sekprodi', 'Sekretaris Program Studi Teknologi Rekayasa Perangkat Lunak', 'Sekretaris Program Studi Teknologi Rekayasa Perangkat Lunak'],
            ['sekprodi', 'Sekretaris Sekretaris Program Studi Teknologi Rekayasa Perangkat Lunak', 'Sekretaris Program Studi Teknologi Rekayasa Perangkat Lunak'],
        ];

        foreach ($cases as [$roleKey, $unitName, $expected]) {
            $this->assertSame($expected, $service->formatAcademicOfficeTitle($roleKey, $unitName));
            $this->assertStringNotContainsString('Ketua Ketua', $service->formatAcademicOfficeTitle($roleKey, $unitName));
            $this->assertStringNotContainsString('Sekretaris Sekretaris', $service->formatAcademicOfficeTitle($roleKey, $unitName));
            $this->assertStringNotContainsString('Departemen Departemen', $service->formatAcademicOfficeTitle($roleKey, $unitName));
            $this->assertStringNotContainsString('Program Studi Program Studi', $service->formatAcademicOfficeTitle($roleKey, $unitName));
        }
    }
}
