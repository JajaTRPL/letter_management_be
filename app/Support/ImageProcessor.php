<?php

namespace App\Support;

use RuntimeException;

/**
 * GD-based room photo processing. No external dependency.
 *
 * Every accepted image is decoded and re-encoded to JPEG, which inherently
 * strips EXIF/GPS metadata. Output variants:
 *   thumb   ≤ 480 px wide, q75  (catalog cards)
 *   display ≤ 1600 px wide, q80 (detail gallery)
 *   full    ≤ 2560 px wide, q85 (optional zoom)
 *
 * Guards: MIME sniffing via getimagesize (never trusts filename/extension),
 * min/max dimensions, and a pixel-count cap that rejects decompression
 * bombs BEFORE any decode allocates memory.
 */
class ImageProcessor
{
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MIN_DIMENSION = 400;
    public const MAX_DIMENSION = 8000;
    public const MAX_PIXELS = 24_000_000; // ~24 MP decompression-bomb cap

    /** variant => [max width, JPEG quality] */
    public const VARIANTS = [
        'thumb' => [480, 75],
        'display' => [1600, 80],
        'full' => [2560, 85],
    ];

    /**
     * Process an image file into JPEG variant binaries + metadata.
     *
     * @return array{
     *   mime: string,
     *   source_width: int,
     *   source_height: int,
     *   variants: array<string, array{binary: string, width: int, height: int, size_bytes: int, checksum_sha256: string}>
     * }
     *
     * @throws RuntimeException with a UI-safe Indonesian message.
     */
    public function process(string $sourcePath): array
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new RuntimeException('File bukan gambar yang valid.');
        }

        [$width, $height] = $info;
        $mime = $info['mime'] ?? '';

        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException('Format gambar harus JPG, PNG, atau WebP.');
        }

        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            throw new RuntimeException(
                'Dimensi gambar terlalu kecil. Minimal ' . self::MIN_DIMENSION . ' piksel pada tiap sisi.'
            );
        }

        // Reject oversized/decompression-bomb images before decoding.
        if (
            $width > self::MAX_DIMENSION
            || $height > self::MAX_DIMENSION
            || ($width * $height) > self::MAX_PIXELS
        ) {
            throw new RuntimeException('Dimensi gambar terlalu besar. Maksimal 8000 piksel per sisi.');
        }

        $source = $this->decode($sourcePath, $mime);
        $source = $this->normalizeOrientation($source, $sourcePath, $mime);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $variants = [];
        foreach (self::VARIANTS as $name => [$maxWidth, $quality]) {
            $variants[$name] = $this->encodeVariant($source, $sourceWidth, $sourceHeight, $maxWidth, $quality);
        }

        imagedestroy($source);

        return [
            'mime' => 'image/jpeg',
            'source_width' => $sourceWidth,
            'source_height' => $sourceHeight,
            'variants' => $variants,
        ];
    }

    /** @return \GdImage */
    private function decode(string $path, string $mime)
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException('Gambar tidak dapat diproses. Silakan gunakan file JPG atau PNG lain.');
        }

        return $image;
    }

    /**
     * Rotate JPEGs whose EXIF Orientation says the pixels are stored
     * rotated, so re-encoded output (EXIF-free) still displays upright.
     *
     * @param  \GdImage $image
     * @return \GdImage
     */
    private function normalizeOrientation($image, string $path, string $mime)
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * @param  \GdImage $source
     * @return array{binary: string, width: int, height: int, size_bytes: int, checksum_sha256: string}
     */
    private function encodeVariant($source, int $sourceWidth, int $sourceHeight, int $maxWidth, int $quality): array
    {
        $targetWidth = min($sourceWidth, $maxWidth);
        $targetHeight = (int) round($sourceHeight * ($targetWidth / $sourceWidth));

        // Flatten onto white so PNG/WebP alpha degrades gracefully in JPEG.
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        imagecopyresampled(
            $canvas,
            $source,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            $sourceWidth, $sourceHeight,
        );

        ob_start();
        imagejpeg($canvas, null, $quality);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        if ($binary === '') {
            throw new RuntimeException('Gambar tidak dapat diproses. Silakan coba lagi.');
        }

        return [
            'binary' => $binary,
            'width' => $targetWidth,
            'height' => $targetHeight,
            'size_bytes' => strlen($binary),
            'checksum_sha256' => hash('sha256', $binary),
        ];
    }
}
