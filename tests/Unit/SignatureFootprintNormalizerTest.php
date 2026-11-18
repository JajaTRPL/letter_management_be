<?php

namespace Tests\Unit;

use App\Support\SignatureFootprintNormalizer;
use Tests\TestCase;

/**
 * Whatever size, margins or background a Kaprodi's paraf upload has, the
 * normalized image has the reference canvas and its ink fills the reference
 * ink box the same way, so every paraf prints with the same footprint.
 */
class SignatureFootprintNormalizerTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_uploads_of_different_sizes_get_the_same_footprint(): void
    {
        $normalizer = new SignatureFootprintNormalizer();

        // Small transparent PNG, ink tight to the edges.
        $small = $this->drawParaf(200, 120, 0, 0, 200, 120, transparent: true);
        // Huge white-background JPG with lots of empty margin (scan/photo).
        $huge = $this->drawParaf(3000, 2000, 1000, 700, 1200, 720, transparent: false, jpeg: true);

        $boxes = [];
        foreach ([$small, $huge] as $source) {
            $output = $normalizer->paraf($source);
            $this->assertNotSame($source, $output);
            [$width, $height] = getimagesize($output);
            $this->assertSame([...SignatureFootprintNormalizer::PARAF['canvas']], [$width, $height]);
            $boxes[] = $this->inkBox($output);
        }

        // Both inks have a 5:3 shape, so both fill the box width the same way.
        foreach ([0, 1, 2, 3] as $i) {
            $this->assertEqualsWithDelta($boxes[0][$i], $boxes[1][$i], 6, 'Ink box edge ' . $i . ' differs.');
        }
        $this->assertEqualsWithDelta(SignatureFootprintNormalizer::PARAF['ink_box'][0], $boxes[0][2] - $boxes[0][0] + 1, 4);
    }

    public function test_kadep_signatures_of_different_sizes_fill_the_same_box_and_keep_a_colored_stamp(): void
    {
        $normalizer = new SignatureFootprintNormalizer();
        [$canvasWidth, $canvasHeight] = SignatureFootprintNormalizer::KADEP_SIGNATURE['canvas'];

        // Small tight signature vs. big photo with a wide white margin.
        $small = $this->drawParaf(380, 200, 0, 0, 380, 200, transparent: true);
        $big = $this->drawParaf(2400, 1800, 600, 500, 1140, 600, transparent: false, jpeg: true);

        $boxes = [];
        foreach ([$small, $big] as $source) {
            $output = $normalizer->kadepSignature($source);
            $this->assertSame([$canvasWidth, $canvasHeight], array_slice(getimagesize($output), 0, 2));
            $boxes[] = $this->inkBox($output);
        }
        foreach ([0, 1, 2, 3] as $i) {
            $this->assertEqualsWithDelta($boxes[0][$i], $boxes[1][$i], 6, 'Ink box edge ' . $i . ' differs.');
        }

        // A red department stamp on white paper keeps its color.
        $stamped = imagecreatetruecolor(600, 400);
        imagefill($stamped, 0, 0, imagecolorallocate($stamped, 255, 255, 255));
        imagefilledellipse($stamped, 300, 200, 240, 240, imagecolorallocate($stamped, 200, 30, 40));
        $stampedPath = tempnam(sys_get_temp_dir(), 'ttd_cap_') . '.png';
        $this->files[] = $stampedPath;
        imagepng($stamped, $stampedPath);

        $output = imagecreatefrompng($normalizer->kadepSignature($stampedPath));
        $center = imagecolorat($output, intdiv($canvasWidth, 2), intdiv($canvasHeight, 2));
        $this->assertSame([200, 30, 40], [($center >> 16) & 0xFF, ($center >> 8) & 0xFF, $center & 0xFF]);
        $this->assertSame(127, (imagecolorat($output, 0, 0) >> 24) & 0x7F);
    }

    public function test_white_background_becomes_transparent(): void
    {
        $output = (new SignatureFootprintNormalizer())->paraf(
            $this->drawParaf(400, 300, 100, 100, 200, 100, transparent: false),
        );

        $image = imagecreatefrompng($output);
        $corner = imagecolorat($image, 0, 0);
        $this->assertSame(127, ($corner >> 24) & 0x7F);
    }

    public function test_unreadable_file_is_returned_unchanged(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paraf_bad_');
        $this->files[] = $path;
        file_put_contents($path, 'not an image');

        $this->assertSame($path, (new SignatureFootprintNormalizer())->paraf($path));
    }

    private function drawParaf(
        int $width,
        int $height,
        int $inkX,
        int $inkY,
        int $inkWidth,
        int $inkHeight,
        bool $transparent,
        bool $jpeg = false,
    ): string {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $background = $transparent
            ? imagecolorallocatealpha($image, 0, 0, 0, 127)
            : imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $background);
        imagefilledrectangle($image, $inkX, $inkY, $inkX + $inkWidth - 1, $inkY + $inkHeight - 1, imagecolorallocate($image, 20, 30, 120));

        $path = tempnam(sys_get_temp_dir(), 'paraf_src_') . ($jpeg ? '.jpg' : '.png');
        $this->files[] = $path;
        $jpeg ? imagejpeg($image, $path, 95) : imagepng($image, $path);

        return $path;
    }

    /** @return array{int, int, int, int} */
    private function inkBox(string $path): array
    {
        $image = imagecreatefrompng($path);
        $minX = PHP_INT_MAX;
        $minY = PHP_INT_MAX;
        $maxX = -1;
        $maxY = -1;
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 60) {
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
}
