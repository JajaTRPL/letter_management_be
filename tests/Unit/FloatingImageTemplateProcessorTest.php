<?php

namespace Tests\Unit;

use App\Support\FloatingImageTemplateProcessor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class FloatingImageTemplateProcessorTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_paraf_becomes_floating_picture_next_to_its_line(): void
    {
        $template = $this->makeTemplate(['${nama_kadep}', 'NIP. ${nip_kadep} ${paraf}']);

        $processor = new FloatingImageTemplateProcessor($template);
        $processor->setValue('nama_kadep', 'Dr. Kadep');
        $processor->setValue('nip_kadep', '197501012005011003');
        $processor->setFloatingImageValue('paraf', ['path' => $this->parafPath(), 'width' => 80, 'height' => 24, 'ratio' => false]);

        [$xml, $rels] = $this->saveAndRead($processor);

        $this->assertStringNotContainsString('${paraf}', $xml);
        $this->assertStringNotContainsString('<w:pict>', $xml, 'paraf must not stay an inline VML picture');
        $this->assertSame(1, substr_count($xml, '<wp:anchor'));
        $this->assertStringContainsString('<wp:positionH relativeFrom="character"><wp:posOffset>0</wp:posOffset></wp:positionH>', $xml);
        // -9pt: half of the 24px (18pt) paraf above the top of the NIP line.
        $this->assertStringContainsString('<wp:positionV relativeFrom="line"><wp:posOffset>-114300</wp:posOffset></wp:positionV>', $xml);
        $this->assertStringContainsString('<wp:wrapNone/>', $xml);
        // 80x24px in EMU.
        $this->assertStringContainsString('<wp:extent cx="762000" cy="228600"/>', $xml);

        // The anchor stays in the NIP paragraph, after the NIP text.
        $this->assertMatchesRegularExpression('#NIP\. 197501012005011003 </w:t>.*?<wp:anchor#s', $xml);

        // The picture points at a real image relationship.
        $this->assertMatchesRegularExpression('/r:embed="(rId\d+)"/', $xml);
        preg_match('/r:embed="(rId\d+)"/', $xml, $m);
        $this->assertMatchesRegularExpression('/Id="' . $m[1] . '"[^>]*Target="media\//', $rels);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'document.xml must stay well-formed');
    }

    public function test_every_occurrence_is_floated_and_other_images_stay_inline(): void
    {
        $template = $this->makeTemplate(['${ttd}', 'NIP. 1 ${paraf}', 'NIP. 2 ${paraf}']);

        $processor = new FloatingImageTemplateProcessor($template);
        $processor->setImageValue('ttd', ['path' => $this->parafPath(), 'width' => 150, 'height' => 80, 'ratio' => false]);
        $processor->setFloatingImageValue('paraf', ['path' => $this->parafPath(), 'width' => 80, 'height' => 24, 'ratio' => false]);

        [$xml] = $this->saveAndRead($processor);

        $this->assertStringNotContainsString('${paraf}', $xml);
        $this->assertSame(2, substr_count($xml, '<wp:anchor'));
        $this->assertSame(1, substr_count($xml, '<w:pict>'), 'the regular signature stays inline');
        $this->assertStringContainsString('style="width:150px;height:80px"', $xml);
        $this->assertSame(2, preg_match_all('/<wp:docPr id="(\d+)"/', $xml, $ids));
        $this->assertCount(2, array_unique($ids[1]), 'drawing ids must be unique');
    }

    public function test_missing_placeholder_is_a_no_op(): void
    {
        $template = $this->makeTemplate(['NIP. ${nip_kadep}']);

        $processor = new FloatingImageTemplateProcessor($template);
        $processor->setFloatingImageValue('paraf', ['path' => $this->parafPath(), 'width' => 80, 'height' => 24]);

        [$xml] = $this->saveAndRead($processor);

        $this->assertStringContainsString('${nip_kadep}', $xml);
        $this->assertStringNotContainsString('<wp:anchor', $xml);
    }

    /**
     * @param list<string> $paragraphs
     */
    private function makeTemplate(array $paragraphs): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        foreach ($paragraphs as $text) {
            $section->addText($text);
        }

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.docx';
        $this->tempFiles[] = $path;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    /**
     * @return array{0: string, 1: string} document.xml and its relationships
     */
    private function saveAndRead(FloatingImageTemplateProcessor $processor): array
    {
        $out = tempnam(sys_get_temp_dir(), 'out') . '.docx';
        $this->tempFiles[] = $out;
        $processor->saveAs($out);

        $zip = new ZipArchive();
        $zip->open($out);
        $xml = (string) $zip->getFromName('word/document.xml');
        $rels = (string) $zip->getFromName('word/_rels/document.xml.rels');
        $zip->close();

        return [$xml, $rels];
    }

    private function parafPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/system/paraf.png';
    }
}
