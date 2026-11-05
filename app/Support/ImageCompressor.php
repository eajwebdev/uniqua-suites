<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Re-encodes every uploaded photo, so what is kept is small and carries no hidden data. Phone
 * photos hold their GPS position and device details, and drawing the pixels afresh drops them.
 * The browser shrinks photos first to save mobile data, but only this step is relied on.
 */
class ImageCompressor
{
    public const MAX_EDGE = 1024;

    public const TARGET_BYTES = 50 * 1024;

    /** Longest side and quality, tried in order until the photo is small enough. */
    private const STEPS = [[self::MAX_EDGE, 45], [self::MAX_EDGE, 35], [800, 40], [800, 30], [640, 35], [640, 25], [480, 28], [360, 25], [320, 20], [240, 18], [160, 15]];

    /** Decoding uses about 4 bytes per pixel, so this bounds memory use whatever is uploaded. */
    private const MAX_PIXELS = 40_000_000;

    /** Returns the stored path. Never falls back to keeping the original. */
    public static function store(UploadedFile $file, string $folder, string $field = 'photos', string $disk = 'local'): string
    {
        return self::storeWithMetadata($file, $folder, $field, $disk)['path'];
    }

    /** @return array{path: string, byte_size: int, mime_type: string} */
    public static function storeWithMetadata(UploadedFile $file, string $folder, string $field = 'photos', string $disk = 'local'): array
    {
        $image = self::load($file, $field);
        [$bytes, $extension] = self::encode($image, $field);
        $path = trim($folder, '/').'/'.Str::random(40).'.'.$extension;
        if (! Storage::disk($disk)->put($path, $bytes)) {
            throw ValidationException::withMessages([$field => 'The compressed photo could not be saved. Please try again.']);
        }

        return ['path' => $path, 'byte_size' => strlen($bytes), 'mime_type' => $extension === 'webp' ? 'image/webp' : 'image/jpeg'];
    }

    private static function load(UploadedFile $file, string $field): GdImage
    {
        $path = $file->getRealPath();
        $info = @getimagesize($path);
        if (! $info || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages([$field => $info ? 'This photo is too large. Please use a smaller one.' : 'This file could not be read as a photo.']);
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
        if (! $image) {
            throw ValidationException::withMessages([$field => 'This file could not be read as a photo.']);
        }

        return $info[2] === IMAGETYPE_JPEG ? self::upright($image, $path) : $image;
    }

    /** The turn a phone records in the file's metadata has to be applied, because the metadata is dropped. */
    private static function upright(GdImage $image, string $path): GdImage
    {
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $turned = match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $turned ?: $image;
    }

    private static function resize(GdImage $image, int $edge): GdImage
    {
        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, $edge / max($width, $height));
        [$newWidth, $newHeight] = [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];

        // A white canvas, so transparent PNGs do not turn black.
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }

    /** @return array{0: string, 1: string} */
    private static function encode(GdImage $image, string $field): array
    {
        $webp = function_exists('imagewebp');
        $bytes = '';
        foreach (self::STEPS as [$edge, $quality]) {
            $sized = self::resize($image, $edge);
            ob_start();
            $webp ? imagewebp($sized, null, $quality) : imagejpeg($sized, null, $quality);
            $bytes = (string) ob_get_clean();
            if (strlen($bytes) <= self::TARGET_BYTES) {
                break;
            }
        }
        if (strlen($bytes) > self::TARGET_BYTES) {
            throw ValidationException::withMessages([$field => 'This photo could not be reduced enough. Please take another photo.']);
        }

        return [$bytes, $webp ? 'webp' : 'jpg'];
    }
}
