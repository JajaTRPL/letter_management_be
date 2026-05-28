<?php

namespace Tests\Feature\Workflow;

use App\Services\PasFotoNormalizer;
use RuntimeException;
use Tests\TestCase;

class PasFotoNormalizerTest extends TestCase
{
    private string $tempDir;
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required for pas foto normalization tests.');
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pasfoto_test_' . uniqid('', true);
        @mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_normalize_from_path_produces_600x800_jpeg_derivative(): void
    {
        $source = $this->writeJpegFixture(1200, 1600);

        $derivative = (new PasFotoNormalizer())->normalizeFromPath($source, $this->tempDir);

        $this->assertTrue(is_file($derivative));
        $info = getimagesize($derivative);
        $this->assertNotFalse($info);
        $this->assertSame(600, $info[0]);
        $this->assertSame(800, $info[1]);
        $this->assertSame('image/jpeg', $info['mime']);
    }

    public function test_normalize_from_path_does_not_mutate_original_file(): void
    {
        $source = $this->writeJpegFixture(2000, 1500);
        $originalHash = md5_file($source);
        $originalSize = filesize($source);

        (new PasFotoNormalizer())->normalizeFromPath($source, $this->tempDir);

        $this->assertSame($originalHash, md5_file($source), 'Original pas foto bytes must not change.');
        $this->assertSame($originalSize, filesize($source));
    }

    public function test_normalize_from_path_caps_oversized_input_dimensions(): void
    {
        $source = $this->writeJpegFixture(4000, 3000);

        $derivative = (new PasFotoNormalizer())->normalizeFromPath($source, $this->tempDir);

        $info = getimagesize($derivative);
        $this->assertSame(600, $info[0]);
        $this->assertSame(800, $info[1]);
        $this->assertLessThan(filesize($source), filesize($derivative), 'Derivative should be smaller than oversized source.');
    }

    public function test_normalize_from_path_handles_landscape_via_center_crop_to_portrait(): void
    {
        $source = $this->writeJpegFixture(2000, 800);

        $derivative = (new PasFotoNormalizer())->normalizeFromPath($source, $this->tempDir);

        $info = getimagesize($derivative);
        $this->assertSame(600, $info[0]);
        $this->assertSame(800, $info[1]);
    }

    public function test_normalize_from_path_throws_when_source_missing(): void
    {
        $this->expectException(RuntimeException::class);

        (new PasFotoNormalizer())->normalizeFromPath(
            $this->tempDir . DIRECTORY_SEPARATOR . 'does-not-exist.jpg',
            $this->tempDir,
        );
    }

    public function test_normalize_from_path_throws_when_source_is_not_an_image(): void
    {
        $bogus = $this->tempDir . DIRECTORY_SEPARATOR . 'bogus.jpg';
        file_put_contents($bogus, 'not an image');
        $this->cleanup[] = $bogus;

        $this->expectException(RuntimeException::class);

        (new PasFotoNormalizer())->normalizeFromPath($bogus, $this->tempDir);
    }

    private function writeJpegFixture(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, 200, 220, 240);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'src_' . uniqid('', true) . '.jpg';
        imagejpeg($img, $path, 92);
        imagedestroy($img);

        $this->cleanup[] = $path;

        return $path;
    }
}
