<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Keeps signature images (TTD / TTD + cap) in a format PhpWord can embed.
 *
 * PhpWord's TemplateProcessor::setImageValue() only accepts JPEG, PNG, GIF
 * and BMP, while the profile upload accepts WebP too. WebP signatures are
 * therefore re-encoded to PNG (alpha preserved, so transparent signatures
 * stay transparent on the letter):
 *  - on upload, via storeUploaded();
 *  - at render time for files stored before this existed, via
 *    docxCompatiblePath(), which caches the converted PNG.
 */
class SignatureImageNormalizer
{
    private const CACHE_DIRECTORY = 'signature-png-cache';

    /**
     * Stores an uploaded signature on the public disk. WebP is converted to
     * PNG; other formats are stored as-is. Returns the public-disk path.
     */
    public function storeUploaded(UploadedFile $file, string $directory): string
    {
        $sourcePath = $file->getRealPath() ?: $file->getPathname();

        if (!$this->isWebp($sourcePath)) {
            $path = $file->store($directory, 'public');
            if (!$path) {
                throw new RuntimeException('Failed to store signature image.');
            }

            return $path;
        }

        $png = $this->webpToPngBinary($sourcePath);
        $path = trim($directory, '/') . '/' . Str::random(40) . '.png';

        if (!Storage::disk('public')->put($path, $png)) {
            throw new RuntimeException('Failed to store signature image.');
        }

        return $path;
    }

    /**
     * Returns an absolute path PhpWord can embed: the path itself, or for a
     * WebP file a cached PNG copy. Falls back to the original path when the
     * conversion fails so callers keep their existing error handling.
     */
    public function docxCompatiblePath(?string $absolutePath): ?string
    {
        if (!$absolutePath || !is_file($absolutePath) || !$this->isWebp($absolutePath)) {
            return $absolutePath;
        }

        $cacheKey = sha1($absolutePath . '|' . (int) @filemtime($absolutePath) . '|' . (int) @filesize($absolutePath));
        $cacheDirectory = storage_path('app/' . self::CACHE_DIRECTORY);
        $cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . $cacheKey . '.png';

        if (is_file($cachePath)) {
            return $cachePath;
        }

        try {
            if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
                throw new RuntimeException('Cannot create signature cache directory.');
            }

            $temporaryPath = $cachePath . '.' . Str::random(8) . '.tmp';
            if (file_put_contents($temporaryPath, $this->webpToPngBinary($absolutePath)) === false) {
                throw new RuntimeException('Cannot write converted signature.');
            }
            rename($temporaryPath, $cachePath);

            return $cachePath;
        } catch (\Throwable $exception) {
            Log::warning('WebP signature conversion failed: ' . $exception->getMessage(), [
                'path' => $absolutePath,
            ]);

            return $absolutePath;
        }
    }

    public function isWebp(string $path): bool
    {
        $info = @getimagesize($path);
        if ($info !== false) {
            return ($info['mime'] ?? null) === 'image/webp';
        }

        // getimagesize() may not recognise some WebP variants; sniff the RIFF header.
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = (string) fread($handle, 12);
        fclose($handle);

        return strlen($header) === 12
            && substr($header, 0, 4) === 'RIFF'
            && substr($header, 8, 4) === 'WEBP';
    }

    private function webpToPngBinary(string $path): string
    {
        if (!function_exists('imagecreatefromwebp')) {
            throw new RuntimeException('Server tidak mendukung konversi gambar WebP. Silakan unggah tanda tangan dalam format PNG atau JPG.');
        }

        $image = @imagecreatefromwebp($path);
        if ($image === false) {
            throw new RuntimeException('Gambar tanda tangan WebP tidak dapat diproses. Silakan unggah dalam format PNG atau JPG.');
        }

        try {
            if (!imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $ok = imagepng($image, null, 6);
            $binary = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if (!$ok || $binary === '') {
            throw new RuntimeException('Gambar tanda tangan WebP tidak dapat diproses. Silakan unggah dalam format PNG atau JPG.');
        }

        return $binary;
    }
}
