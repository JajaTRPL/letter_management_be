<?php

namespace Tests\Unit;

use App\Support\ImageProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ImageProcessorTest extends TestCase
{
    private ImageProcessor $processor;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new ImageProcessor();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_valid_jpeg_produces_three_jpeg_variants_with_metadata(): void
    {
        $path = $this->makeImage(2000, 1200, 'jpeg');

        $result = $this->processor->process($path);

        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertSame(2000, $result['source_width']);
        $this->assertSame(1200, $result['source_height']);
        $this->assertSame(['thumb', 'display', 'full'], array_keys($result['variants']));

        foreach ($result['variants'] as $variant) {
            // Every variant is a real JPEG (EXIF-free by re-encoding).
            $this->assertSame("\xFF\xD8", substr($variant['binary'], 0, 2));
            $this->assertSame(strlen($variant['binary']), $variant['size_bytes']);
            $this->assertSame(hash('sha256', $variant['binary']), $variant['checksum_sha256']);
        }

        $this->assertSame(480, $result['variants']['thumb']['width']);
        $this->assertSame(1600, $result['variants']['display']['width']);
        // Source narrower than the full cap: never upscaled.
        $this->assertSame(2000, $result['variants']['full']['width']);
    }

    public function test_png_with_alpha_is_flattened_and_accepted(): void
    {
        $path = $this->makeImage(800, 600, 'png');

        $result = $this->processor->process($path);

        $this->assertSame(480, $result['variants']['thumb']['width']);
        $this->assertSame(800, $result['variants']['display']['width']);
    }

    public function test_non_image_file_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
        file_put_contents($path, 'bukan gambar sama sekali');
        $this->tempFiles[] = $path;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File bukan gambar yang valid.');
        $this->processor->process($path);
    }

    public function test_disallowed_mime_is_rejected(): void
    {
        // GIF is a real image type but not on the allowlist.
        $image = imagecreatetruecolor(500, 500);
        $path = tempnam(sys_get_temp_dir(), 'img') . '.gif';
        imagegif($image, $path);
        imagedestroy($image);
        $this->tempFiles[] = $path;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Format gambar harus JPG, PNG, atau WebP.');
        $this->processor->process($path);
    }

    public function test_too_small_image_is_rejected(): void
    {
        $path = $this->makeImage(200, 200, 'jpeg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dimensi gambar terlalu kecil');
        $this->processor->process($path);
    }

    public function test_decompression_bomb_is_rejected_before_decode(): void
    {
        // Hand-built PNG header declaring 9000×9000 with no pixel data:
        // getimagesize trusts the IHDR, so the guard must fire before any
        // decode attempt allocates memory.
        $ihdr = pack('N', 9000) . pack('N', 9000) . "\x08\x06\x00\x00\x00";
        $chunk = 'IHDR' . $ihdr;
        $png = "\x89PNG\r\n\x1a\n"
            . pack('N', strlen($ihdr)) . $chunk . pack('N', crc32($chunk));

        $path = tempnam(sys_get_temp_dir(), 'img') . '.png';
        file_put_contents($path, $png);
        $this->tempFiles[] = $path;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dimensi gambar terlalu besar');
        $this->processor->process($path);
    }

    public function test_output_metadata_never_contains_source_filename_or_path(): void
    {
        $path = $this->makeImage(900, 700, 'jpeg');

        $result = $this->processor->process($path);

        $flat = json_encode(array_map(
            fn (array $variant) => array_diff_key($variant, ['binary' => true]),
            $result['variants'],
        ));
        $this->assertStringNotContainsString(basename($path), $flat);
        $this->assertStringNotContainsString(sys_get_temp_dir(), (string) $flat);
    }

    // ─────────────────────────── helpers ───────────────────────────

    private function makeImage(int $width, int $height, string $format): string
    {
        $image = imagecreatetruecolor($width, $height);
        $teal = imagecolorallocate($image, 15, 118, 110);
        imagefilledrectangle($image, 0, 0, $width, $height, $teal);

        $path = tempnam(sys_get_temp_dir(), 'img') . '.' . $format;
        match ($format) {
            'png' => imagepng($image, $path),
            default => imagejpeg($image, $path, 90),
        };
        imagedestroy($image);

        $this->tempFiles[] = $path;

        return $path;
    }
}
