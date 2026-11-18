<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gives every uploaded paraf / Kadep signature the same footprint on the
 * letter, whatever the size, margins or background of the uploaded file:
 *  1. crop the empty margins around the ink (transparent or near-white);
 *  2. turn a near-white background transparent (JPG / scanned uploads);
 *  3. scale the ink to fit a fixed ink box, keeping its proportions;
 *  4. center it on a fixed-size transparent canvas.
 *
 * The templates then fit that canvas into their image box, so all uploads
 * print alike. Colored ink (e.g. a department stamp) is kept; only
 * near-white pixels are treated as background. Results are cached by
 * profile + source path/mtime/size; on any failure the original file is
 * returned unchanged so callers keep their existing behaviour.
 */
class SignatureFootprintNormalizer
{
    private const CACHE_DIRECTORY = 'signature-footprint-cache';
    private const VERSION = 'v1';

    /**
     * Paraf: canvas and ink box measured from resources/system/paraf.png, so an
     * uploaded paraf sits exactly where the reference paraf did.
     */
    public const PARAF = [
        'name' => 'paraf',
        'canvas' => [750, 440],
        'ink_box' => [458, 403],
        'center' => [371.5, 220.0],
    ];

    /**
     * Kadep signature (regular and stamped): canvas with the same 19:8 shape
     * as the template image box (190x80; the height is kept from the old
     * 150x80 box so the paraf on the NIP line never gets pushed to a second
     * page); the ink fills it up to a small margin.
     */
    public const KADEP_SIGNATURE = [
        'name' => 'kadep-signature',
        'canvas' => [950, 400],
        'ink_box' => [920, 380],
        'center' => [475.0, 200.0],
    ];

    private const ALPHA_EMPTY_THRESHOLD = 120; // GD alpha: 0 opaque .. 127 transparent
    private const WHITE_THRESHOLD = 235;
    private const MAX_WORKING_SIDE = 1500;

    public function paraf(?string $absolutePath): ?string
    {
        return $this->normalizedPath($absolutePath, self::PARAF);
    }

    public function kadepSignature(?string $absolutePath): ?string
    {
        return $this->normalizedPath($absolutePath, self::KADEP_SIGNATURE);
    }

    /**
     * @param array{name: string, canvas: array{int, int}, ink_box: array{int, int}, center: array{float, float}} $profile
     */
    public function normalizedPath(?string $absolutePath, array $profile): ?string
    {
        if (!$absolutePath || !is_file($absolutePath) || !function_exists('imagecreatefromstring')) {
            return $absolutePath;
        }

        $cacheKey = sha1(implode('|', [
            self::VERSION,
            json_encode($profile),
            $absolutePath,
            (int) @filemtime($absolutePath),
            (int) @filesize($absolutePath),
        ]));
        $cacheDirectory = storage_path('app/' . self::CACHE_DIRECTORY);
        $cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . $profile['name'] . '-' . $cacheKey . '.png';

        if (is_file($cachePath)) {
            return $cachePath;
        }

        try {
            $binary = $this->normalizeBinary($absolutePath, $profile);

            if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
                throw new RuntimeException('Cannot create signature cache directory.');
            }

            $temporaryPath = $cachePath . '.' . Str::random(8) . '.tmp';
            if (file_put_contents($temporaryPath, $binary) === false) {
                throw new RuntimeException('Cannot write normalized signature.');
            }
            rename($temporaryPath, $cachePath);

            return $cachePath;
        } catch (\Throwable $exception) {
            Log::warning('Signature normalization failed: ' . $exception->getMessage(), [
                'path' => $absolutePath,
                'profile' => $profile['name'],
            ]);

            return $absolutePath;
        }
    }

    private function normalizeBinary(string $path, array $profile): string
    {
        $contents = file_get_contents($path);
        $source = $contents !== false ? @imagecreatefromstring($contents) : false;
        if ($source === false) {
            throw new RuntimeException('Unreadable image.');
        }

        try {
            if (!imageistruecolor($source)) {
                imagepalettetotruecolor($source);
            }

            // Large photos/scans: shrink first so the per-pixel passes stay fast.
            $longestSide = max(imagesx($source), imagesy($source));
            if ($longestSide > self::MAX_WORKING_SIDE) {
                $factor = self::MAX_WORKING_SIDE / $longestSide;
                $smaller = $this->transparentCanvas(
                    max(1, (int) round(imagesx($source) * $factor)),
                    max(1, (int) round(imagesy($source) * $factor)),
                );
                imagecopyresampled($smaller, $source, 0, 0, 0, 0, imagesx($smaller), imagesy($smaller), imagesx($source), imagesy($source));
                imagedestroy($source);
                $source = $smaller;
            }

            $width = imagesx($source);
            $height = imagesy($source);
            [$minX, $minY, $maxX, $maxY] = $this->inkBounds($source, $width, $height);
            if ($maxX < $minX || $maxY < $minY) {
                throw new RuntimeException('Image has no visible ink.');
            }

            $inkWidth = $maxX - $minX + 1;
            $inkHeight = $maxY - $minY + 1;

            // Crop to the ink and make the near-white background transparent.
            $ink = $this->transparentCanvas($inkWidth, $inkHeight);
            try {
                for ($y = 0; $y < $inkHeight; $y++) {
                    for ($x = 0; $x < $inkWidth; $x++) {
                        $color = imagecolorat($source, $minX + $x, $minY + $y);
                        if ($this->isEmpty($color)) {
                            continue;
                        }
                        imagesetpixel($ink, $x, $y, $color);
                    }
                }

                [$canvasWidth, $canvasHeight] = $profile['canvas'];
                [$boxWidth, $boxHeight] = $profile['ink_box'];
                [$centerX, $centerY] = $profile['center'];

                $scale = min($boxWidth / $inkWidth, $boxHeight / $inkHeight);
                $targetWidth = max(1, (int) round($inkWidth * $scale));
                $targetHeight = max(1, (int) round($inkHeight * $scale));
                $targetX = (int) round($centerX - $targetWidth / 2);
                $targetY = (int) round($centerY - $targetHeight / 2);

                $canvas = $this->transparentCanvas($canvasWidth, $canvasHeight);
                try {
                    imagealphablending($canvas, true);
                    imagecopyresampled($canvas, $ink, $targetX, $targetY, 0, 0, $targetWidth, $targetHeight, $inkWidth, $inkHeight);
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);

                    ob_start();
                    $ok = imagepng($canvas, null, 6);
                    $binary = (string) ob_get_clean();
                } finally {
                    imagedestroy($canvas);
                }
            } finally {
                imagedestroy($ink);
            }
        } finally {
            imagedestroy($source);
        }

        if (!$ok || $binary === '') {
            throw new RuntimeException('Cannot encode normalized signature.');
        }

        return $binary;
    }

    /** @return array{int, int, int, int} */
    private function inkBounds(\GdImage $image, int $width, int $height): array
    {
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($this->isEmpty(imagecolorat($image, $x, $y))) {
                    continue;
                }
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    private function isEmpty(int $color): bool
    {
        $alpha = ($color >> 24) & 0x7F;
        if ($alpha >= self::ALPHA_EMPTY_THRESHOLD) {
            return true;
        }

        return (($color >> 16) & 0xFF) > self::WHITE_THRESHOLD
            && (($color >> 8) & 0xFF) > self::WHITE_THRESHOLD
            && ($color & 0xFF) > self::WHITE_THRESHOLD;
    }

    private function transparentCanvas(int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }
}
