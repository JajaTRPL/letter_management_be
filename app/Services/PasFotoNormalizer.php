<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PasFotoNormalizer
{
    private const TARGET_WIDTH = 600;
    private const TARGET_HEIGHT = 800;
    private const MIN_SOURCE_SIDE = 200;
    private const DOCUMENT_MIN_SOURCE_SIDE = 1;
    private const JPEG_QUALITY = 90;
    private const STORAGE_FOLDER = 'profiles/fotos';
    private const STORAGE_DISK = 'public';

    /**
     * Normalize an uploaded pas foto and persist it to the public disk.
     *
     * Pipeline: load -> apply EXIF orientation -> center-crop to 3:4 portrait
     * -> resample to 600x800 -> save as JPEG. The output is orientation-stable
     * (no EXIF dependency) so consumers like PhpWord render it upright.
     *
     * @return string Relative disk path (e.g. "profiles/fotos/abc.jpg").
     * @throws RuntimeException When the file cannot be decoded, is too small, or fails to save.
     */
    public function normalize(UploadedFile $file): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is required to process pas foto.');
        }

        $sourcePath = $file->getRealPath();
        if (!$sourcePath || !is_file($sourcePath)) {
            throw new RuntimeException('Pas foto upload tidak dapat dibaca.');
        }

        $image = $this->renderNormalizedFromPath($sourcePath, self::MIN_SOURCE_SIDE);

        $relativePath = self::STORAGE_FOLDER . '/' . Str::random(40) . '.jpg';
        $absolutePath = Storage::disk(self::STORAGE_DISK)->path($relativePath);

        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($image);
            throw new RuntimeException('Gagal menyiapkan direktori penyimpanan pas foto.');
        }

        $saved = imagejpeg($image, $absolutePath, self::JPEG_QUALITY);
        imagedestroy($image);

        if (!$saved) {
            throw new RuntimeException('Gagal menyimpan pas foto yang sudah dinormalisasi.');
        }

        return $relativePath;
    }

    /**
     * Build a normalized JPEG derivative from an already-stored pas foto file
     * for use during document generation. The original file is never modified.
     *
     * The derivative is written to a fresh path inside $tempDirectory, which the
     * caller owns (and must clean up). Min-source-side is relaxed because this
     * path operates on already-stored profile photos: rejecting a too-small
     * legacy file here would crash document generation, which the upload-time
     * normalizer already prevented for fresh uploads.
     *
     * @return string Absolute path of the temp JPEG derivative.
     * @throws RuntimeException When the source cannot be decoded or saved.
     */
    public function normalizeFromPath(string $sourceAbsolutePath, string $tempDirectory): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is required to process pas foto.');
        }

        if (!is_file($sourceAbsolutePath) || !is_readable($sourceAbsolutePath)) {
            throw new RuntimeException('Pas foto sumber tidak dapat dibaca.');
        }

        if (!is_dir($tempDirectory) && !@mkdir($tempDirectory, 0775, true) && !is_dir($tempDirectory)) {
            throw new RuntimeException('Gagal menyiapkan direktori sementara untuk pas foto.');
        }

        $image = $this->renderNormalizedFromPath($sourceAbsolutePath, self::DOCUMENT_MIN_SOURCE_SIDE);

        $targetPath = rtrim($tempDirectory, "/\\") . DIRECTORY_SEPARATOR
            . 'pas_foto_' . str_replace('.', '', uniqid('', true)) . '.jpg';

        $saved = imagejpeg($image, $targetPath, self::JPEG_QUALITY);
        imagedestroy($image);

        if (!$saved) {
            throw new RuntimeException('Gagal menyimpan pas foto sementara untuk dokumen.');
        }

        return $targetPath;
    }

    /**
     * Shared pipeline: load -> EXIF orientation -> center-crop 3:4 -> resample.
     *
     * @return \GdImage Normalized in-memory image. Caller owns destroy.
     */
    private function renderNormalizedFromPath(string $sourcePath, int $minSourceSide): \GdImage
    {
        $info = @getimagesize($sourcePath);
        if (!$info || empty($info['mime'])) {
            throw new RuntimeException('Pas foto bukan gambar yang valid.');
        }

        $mime = strtolower($info['mime']);
        $image = $this->loadImage($sourcePath, $mime);
        if (!$image) {
            throw new RuntimeException('Pas foto tidak dapat diproses (format tidak didukung atau berkas rusak).');
        }

        $image = $this->applyExifOrientation($image, $sourcePath, $mime);

        if (imagesx($image) < $minSourceSide || imagesy($image) < $minSourceSide) {
            imagedestroy($image);
            throw new RuntimeException(
                'Pas foto terlalu kecil. Sisi terpendek minimal ' . $minSourceSide . 'px.'
            );
        }

        $image = $this->centerCropPortrait($image, self::TARGET_WIDTH, self::TARGET_HEIGHT);
        $image = $this->resampleTo($image, self::TARGET_WIDTH, self::TARGET_HEIGHT);

        return $image;
    }

    private function loadImage(string $path, string $mime): ?\GdImage
    {
        $img = match ($mime) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            default => null,
        };

        return $img instanceof \GdImage ? $img : null;
    }

    /**
     * Apply EXIF orientation so the bitmap is physically upright. Only meaningful
     * for JPEG (EXIF segment); other formats are returned unchanged.
     */
    private function applyExifOrientation(\GdImage $image, string $path, string $mime): \GdImage
    {
        if (!in_array($mime, ['image/jpeg', 'image/jpg', 'image/pjpeg'], true)) {
            return $image;
        }
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;

        // GD imagerotate angle is counter-clockwise; flips mutate in place.
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return $image;
            case 3:
                $rotated = imagerotate($image, 180, 0);
                imagedestroy($image);
                return $rotated;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                return $image;
            case 5:
                $rotated = imagerotate($image, -90, 0);
                imagedestroy($image);
                imageflip($rotated, IMG_FLIP_HORIZONTAL);
                return $rotated;
            case 6:
                $rotated = imagerotate($image, -90, 0);
                imagedestroy($image);
                return $rotated;
            case 7:
                $rotated = imagerotate($image, 90, 0);
                imagedestroy($image);
                imageflip($rotated, IMG_FLIP_HORIZONTAL);
                return $rotated;
            case 8:
                $rotated = imagerotate($image, 90, 0);
                imagedestroy($image);
                return $rotated;
            default:
                return $image;
        }
    }

    private function centerCropPortrait(\GdImage $image, int $targetW, int $targetH): \GdImage
    {
        $srcW = imagesx($image);
        $srcH = imagesy($image);
        $targetRatio = $targetW / $targetH;
        $srcRatio = $srcW / $srcH;

        if ($srcRatio > $targetRatio) {
            $cropW = (int) round($srcH * $targetRatio);
            $cropH = $srcH;
            $cropX = (int) round(($srcW - $cropW) / 2);
            $cropY = 0;
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
            $cropX = 0;
            $cropY = (int) round(($srcH - $cropH) / 2);
        }

        $cropped = imagecrop($image, [
            'x' => max(0, $cropX),
            'y' => max(0, $cropY),
            'width' => max(1, $cropW),
            'height' => max(1, $cropH),
        ]);

        if (!$cropped instanceof \GdImage) {
            imagedestroy($image);
            throw new RuntimeException('Gagal melakukan crop pas foto.');
        }

        imagedestroy($image);
        return $cropped;
    }

    private function resampleTo(\GdImage $image, int $targetW, int $targetH): \GdImage
    {
        $srcW = imagesx($image);
        $srcH = imagesy($image);

        $dst = imagecreatetruecolor($targetW, $targetH);
        if (!$dst instanceof \GdImage) {
            imagedestroy($image);
            throw new RuntimeException('Gagal mengalokasikan kanvas pas foto.');
        }

        // Fill with white in case the resample step somehow leaves transparent pixels.
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $white);

        imagecopyresampled($dst, $image, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);
        imagedestroy($image);

        return $dst;
    }
}
