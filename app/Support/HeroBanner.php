<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Storage;

/**
 * The lobby hero artwork, as an operator set it.
 *
 * Two images, both optional: a wide one for desktop and tablet, and a taller
 * one for phones. Either falls back to the bundled artwork, and the mobile
 * slot falls back to the desktop image before that, so a brand that uploads
 * one picture gets it everywhere.
 *
 * Paths are relative to the `public` disk — the same disk and the same
 * `storage/` symlink the game artwork and promotion images already use — so a
 * host that serves those serves this too.
 */
final class HeroBanner
{
    public const SETTING_DESKTOP = 'hero_image_desktop';
    public const SETTING_MOBILE = 'hero_image_mobile';
    public const SETTING_ALT = 'hero_image_alt';

    public const DEFAULT_DESKTOP = 'images/hero-casino.svg';
    public const DEFAULT_MOBILE = 'images/hero-casino-mobile.svg';

    /** The stored path for a slot, or null when the default artwork is in use. */
    public static function path(string $slot): ?string
    {
        $value = trim((string) PlatformSetting::valueFor(
            $slot === 'mobile' ? self::SETTING_MOBILE : self::SETTING_DESKTOP,
            ''
        ));

        return $value !== '' ? $value : null;
    }

    public static function desktopUrl(): string
    {
        $path = self::path('desktop');

        return $path ? self::storageUrl($path) : asset(self::DEFAULT_DESKTOP);
    }

    public static function mobileUrl(): string
    {
        $path = self::path('mobile');

        if ($path) {
            return self::storageUrl($path);
        }

        // A brand that uploaded only the wide picture should still see it on
        // a phone rather than the stock mobile artwork.
        return self::path('desktop') ? self::desktopUrl() : asset(self::DEFAULT_MOBILE);
    }

    public static function alt(): string
    {
        $alt = trim((string) PlatformSetting::valueFor(self::SETTING_ALT, ''));

        return $alt !== '' ? $alt : 'Casino chips, dice and cards on a dark table';
    }

    public static function isCustom(string $slot): bool
    {
        return self::path($slot) !== null;
    }

    /** Whether a stored file is actually on disk — the admin shows a warning otherwise. */
    public static function exists(string $slot): bool
    {
        $path = self::path($slot);

        if ($path === null) {
            return false;
        }

        try {
            return Storage::disk('public')->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The stored path carries a random suffix (see HeroImageProcessor), so a
     * replaced image always has a new URL and no browser keeps the old one.
     */
    private static function storageUrl(string $path): string
    {
        return asset('storage/'.ltrim($path, '/'));
    }
}
