<?php

namespace Tests\Unit;

use App\Support\SignatureImageNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignatureImageNormalizerTest extends TestCase
{
    private function makeTransparentWebp(): string
    {
        $image = imagecreatetruecolor(40, 20);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 5, 5, 30, 12, imagecolorallocatealpha($image, 0, 0, 255, 0));
        $path = tempnam(sys_get_temp_dir(), 'sig') . '.webp';
        imagewebp($image, $path, 100);
        imagedestroy($image);

        return $path;
    }

    public function test_webp_upload_is_stored_as_transparent_png(): void
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('GD without WebP support.');
        }

        Storage::fake('public');
        $file = new UploadedFile($this->makeTransparentWebp(), 'ttd.webp', 'image/webp', null, true);

        $path = app(SignatureImageNormalizer::class)->storeUploaded($file, 'profiles/signatures');

        $this->assertStringEndsWith('.png', $path);
        $absolute = Storage::disk('public')->path($path);
        $this->assertSame(IMAGETYPE_PNG, getimagesize($absolute)[2]);

        $png = imagecreatefrompng($absolute);
        $alpha = (imagecolorat($png, 0, 0) >> 24) & 0x7F;
        $this->assertSame(127, $alpha, 'transparent background must survive conversion');
    }

    public function test_png_upload_is_stored_unchanged(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('ttd.png', 40, 20);

        $path = app(SignatureImageNormalizer::class)->storeUploaded($file, 'profiles/signatures');

        $this->assertStringEndsWith('.png', $path);
        $this->assertSame(file_get_contents($file->getRealPath()), Storage::disk('public')->get($path));
    }

    public function test_existing_webp_is_converted_for_docx_rendering(): void
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('GD without WebP support.');
        }

        $webp = $this->makeTransparentWebp();
        $normalizer = app(SignatureImageNormalizer::class);

        $converted = $normalizer->docxCompatiblePath($webp);

        $this->assertNotSame($webp, $converted);
        $this->assertSame(IMAGETYPE_PNG, getimagesize($converted)[2]);
        $this->assertSame($converted, $normalizer->docxCompatiblePath($webp), 'conversion is cached');

        $png = tempnam(sys_get_temp_dir(), 'sig') . '.png';
        imagepng(imagecreatetruecolor(4, 4), $png);
        $this->assertSame($png, $normalizer->docxCompatiblePath($png));
    }
}
