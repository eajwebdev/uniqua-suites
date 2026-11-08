<?php

namespace App\Support;

/**
 * Cuts a stand-in photo out of a flyer or rate card for the seeders. Works on any host's GD build:
 * it only uses imagecreatefromstring, imagecreatetruecolor and imagecopyresampled (no imagescale
 * filters, which some shared hosts lack), checks every step, and returns null instead of failing,
 * so a missing image can never stop `php artisan migrate --seed`.
 */
final class SeedImage
{
    /**
     * @param  array{0:int,1:int,2:int,3:int}  $box  x, y, width, height on the source image
     * @return string|null JPEG bytes, or null when the image could not be made
     */
    public static function crop(string $source, array $box, float $scale = 1.0, int $quality = 90): ?string
    {
        if (! extension_loaded('gd') || ! is_file($source) || ! is_readable($source)) {
            return null;
        }

        [$x, $y, $width, $height] = array_map('intval', $box);
        $bytes = @file_get_contents($source);
        // Recognised as an image first, so GD is never handed data it would complain about.
        $image = $bytes === false || $bytes === '' || @getimagesizefromstring($bytes) === false ? false : @imagecreatefromstring($bytes);
        if (! $image || $width <= 0 || $height <= 0) {
            return null;
        }

        // Keep the box inside the image, in case the source file was replaced with a smaller one.
        $x = max(0, min($x, imagesx($image) - 1));
        $y = max(0, min($y, imagesy($image) - 1));
        $width = min($width, imagesx($image) - $x);
        $height = min($height, imagesy($image) - $y);

        $outWidth = max(1, (int) round($width * $scale));
        $outHeight = max(1, (int) round($height * $scale));
        $canvas = @imagecreatetruecolor($outWidth, $outHeight);
        if (! $canvas) {
            return null;
        }
        // A white background, so a transparent PNG does not turn black as a JPEG.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        if (! @imagecopyresampled($canvas, $image, 0, 0, $x, $y, $outWidth, $outHeight, $width, $height)) {
            return null;
        }

        ob_start();
        $ok = @imagejpeg($canvas, null, $quality);
        $jpeg = ob_get_clean();

        return $ok && $jpeg !== '' && $jpeg !== false ? $jpeg : null;
    }
}
