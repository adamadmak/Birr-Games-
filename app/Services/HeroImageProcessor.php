<?php

namespace App\Services;

use App\Models\SiteMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores a hero upload, right-sized and re-encoded.
 *
 * Operators upload whatever their designer sent — a 6 MB PNG is normal — and
 * the lobby is the busiest page on the site. So the image is resized down to
 * what a screen can actually show and re-encoded as WebP where PHP has the
 * codec, which is typically a tenth of the original. Without GD the validated
 * original is stored as-is: a bigger file, never a broken page.
 *
 * WHERE IT GOES
 *
 * Into the `site_media` table, not onto disk. This host has no
 * `public/storage` symlink and replaces the container's filesystem on every
 * deploy, so a file on the public disk 404s and then vanishes. The setting
 * holds a reference of the form `media:<slot>-<random>`; the random part is
 * the URL's version segment, so a replaced image always has a new URL and no
 * browser or CDN keeps serving the old one. A plain path (`hero/…`) is a
 * legacy disk upload and is still understood.
 */
final class HeroImageProcessor
{
    public const DIRECTORY = 'hero';
    public const REFERENCE_PREFIX = 'media:';

    /** Longest edge kept, per slot. Anything wider is scaled down. */
    private const MAX_WIDTH = ['desktop' => 2200, 'mobile' => 1200, 'sports' => 2200];

    private const WEBP_QUALITY = 82;
    private const JPEG_QUALITY = 84;

    /** @return string the reference to save in platform_settings */
    public function store(UploadedFile $file, string $slot): string
    {
        $slot = \App\Support\HeroBanner::slot($slot);

        [$binary, $mime] = $this->optimise($file, self::MAX_WIDTH[$slot]) ?? $this->original($file);

        SiteMedia::ensureTable();
        SiteMedia::query()->updateOrCreate(
            ['slot' => 'hero-'.$slot],
            ['mime' => $mime, 'bytes' => strlen($binary), 'data' => base64_encode($binary)]
        );
        // updateOrCreate only touches updated_at when something changed; a
        // same-bytes re-upload still deserves a fresh ETag.
        SiteMedia::query()->where('slot', 'hero-'.$slot)->update(['updated_at' => now()]);

        return self::REFERENCE_PREFIX.$slot.'-'.Str::lower(Str::random(12));
    }

    /** Whether a stored reference points at the database rather than a disk file. */
    public static function isMedia(?string $reference): bool
    {
        return is_string($reference) && str_starts_with($reference, self::REFERENCE_PREFIX);
    }

    /** The version segment of a `media:` reference, for the URL. */
    public static function versionOf(string $reference): string
    {
        return substr($reference, strlen(self::REFERENCE_PREFIX));
    }

    /** Remove whatever a slot currently holds: its database row, and a legacy disk file if that is what was stored. */
    public function clear(string $slot, ?string $reference = null): void
    {
        $slot = $slot === 'mobile' ? 'mobile' : 'desktop';

        try {
            if (SiteMedia::available()) {
                SiteMedia::query()->where('slot', 'hero-'.$slot)->delete();
            }
        } catch (\Throwable) {
            // Nothing to remove is the outcome wanted.
        }

        $this->deleteLegacyFile($reference);
    }

    /** Drop a legacy disk file that a new upload has superseded. */
    public function deleteLegacyFile(?string $path): void
    {
        if ($path === null || $path === '' || self::isMedia($path) || ! str_starts_with($path, self::DIRECTORY.'/')) {
            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable) {
            // A file that is already gone is the outcome wanted.
        }
    }

    /**
     * The validated upload, untouched, for hosts without GD.
     *
     * @return array{0: string, 1: string}
     */
    private function original(UploadedFile $file): array
    {
        $mime = strtolower((string) $file->getMimeType());

        if (! in_array($mime, SiteMedia::ALLOWED_MIMES, true)) {
            throw new RuntimeException('Unsupported image type.');
        }

        $binary = @file_get_contents($file->getRealPath());

        if ($binary === false || $binary === '') {
            throw new RuntimeException('The uploaded image could not be read.');
        }

        return [$binary, $mime];
    }

    /**
     * @return array{0: string, 1: string}|null  [binary, mime] or null to keep the original
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
                    return [$binary, 'image/webp'];
                }
            } else {
                ob_end_clean();
            }

            // No WebP codec: JPEG for photos, PNG when the source may carry transparency.
            $hasAlpha = in_array(strtolower((string) $file->getMimeType()), ['image/png', 'image/webp'], true);
            ob_start();
            $ok = $hasAlpha ? @imagepng($image, null, 6) : @imagejpeg($image, null, self::JPEG_QUALITY);
            $binary = (string) ob_get_clean();

            if (! $ok || $binary === '') {
                throw new RuntimeException('Image could not be encoded.');
            }

            return [$binary, $hasAlpha ? 'image/png' : 'image/jpeg'];
        } catch (\Throwable) {
            return null;
        } finally {
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }
}
