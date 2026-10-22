<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores a hero upload on the public disk, right-sized and re-encoded.
 *
 * Operators upload whatever their designer sent — a 6 MB PNG is normal — and
 * the lobby is the busiest page on the site. So the image is resized down to
 * what a screen can actually show and re-encoded as WebP where PHP has the
 * codec, which is typically a tenth of the original. Without GD the validated
 * original is stored as-is: a bigger file, never a broken page.
 *
 * Every stored name carries a random suffix, so a replaced image always has a
 * new URL and no browser or CDN keeps serving the old one.
 */
final class HeroImageProcessor
{
    public const DIRECTORY = 'hero';

    /** Longest edge kept, per slot. Anything wider is scaled down. */
    private const MAX_WIDTH = ['desktop' => 2200, 'mobile' => 1200];

    private const WEBP_QUALITY = 84;
    private const JPEG_QUALITY = 86;

    /**
     * @return string the stored path, relative to the public disk
     */
    public function store(UploadedFile $file, string $slot): string
    {
        $slot = $slot === 'mobile' ? 'mobile' : 'desktop';
        $disk = Storage::disk('public');
        $base = self::DIRECTORY.'/'.$slot.'-'.Str::lower(Str::random(12));

        $encoded = $this->optimise($file, self::MAX_WIDTH[$slot]);

        if ($encoded !== null) {
            [$binary, $extension] = $encoded;
            $path = $base.'.'.$extension;
            $disk->put($path, $binary);

            return $path;
        }

        // No GD, or a format GD could not open: keep the validated original.
        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?: 'jpg'));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'jpg';
        $path = $base.'.'.$extension;
        $disk->putFileAs(self::DIRECTORY, $file, basename($path));

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path === null || $path === '' || ! str_starts_with($path, self::DIRECTORY.'/')) {
            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable) {
            // A file that is already gone is the outcome wanted.
        }
    }

    /**
     * @return array{0: string, 1: string}|null  [binary, extension] or null to keep the original
     */
    private function optimise(UploadedFile $file, int $maxWidth): ?array
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $raw = @file_get_contents($file->getRealPath());

        if ($raw === false || $raw === '') {
            return null;
        }

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            return null;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width > $maxWidth) {
                $newHeight = (int) round($height * ($maxWidth / $width));
                $scaled = imagecreatetruecolor($maxWidth, $newHeight);
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                imagecopyresampled($scaled, $image, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $scaled;
            } else {
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }

            ob_start();
            if (function_exists('imagewebp') && @imagewebp($image, null, self::WEBP_QUALITY)) {
                $binary = (string) ob_get_clean();
                if ($binary !== '') {
                    return [$binary, 'webp'];
                }
            } else {
                ob_end_clean();
            }

            // No WebP codec: JPEG for photos, PNG when the source may carry transparency.
            $hasAlpha = in_array(strtolower((string) $file->getMimeType()), ['image/png', 'image/webp', 'image/gif'], true);
            ob_start();
            $ok = $hasAlpha ? @imagepng($image, null, 6) : @imagejpeg($image, null, self::JPEG_QUALITY);
            $binary = (string) ob_get_clean();

            if (! $ok || $binary === '') {
                throw new RuntimeException('Image could not be encoded.');
            }

            return [$binary, $hasAlpha ? 'png' : 'jpg'];
        } catch (\Throwable) {
            return null;
        } finally {
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }
}
