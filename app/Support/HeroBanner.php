<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\SiteMedia;
use App\Services\HeroImageProcessor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The lobby hero artwork, as an operator set it.
 *
 * Two images, both optional: a wide one for desktop and tablet, and a taller
 * one for phones. Either falls back to the bundled artwork, and the mobile
 * slot falls back to the desktop image before that, so a brand that uploads
 * one picture gets it everywhere.
 *
 * A stored reference is either `media:<slot>-<version>` (a row in
 * `site_media`, served by MediaController) or a legacy `hero/…` path on the
 * public disk. A reference whose image is not actually there — a legacy file
 * lost to a deploy, a table that was dropped — is treated as unset, so the
 * lobby shows the bundled artwork rather than a broken image.
 */
final class HeroBanner
{
    public const SETTING_DESKTOP = 'hero_image_desktop';
    public const SETTING_MOBILE = 'hero_image_mobile';
    public const SETTING_ALT = 'hero_image_alt';

    /** "WIDTHxHEIGHT" of what was uploaded, so the lobby can size the hero to the picture. */
    public const SETTING_DESKTOP_SIZE = 'hero_image_desktop_size';
    public const SETTING_MOBILE_SIZE = 'hero_image_mobile_size';

    public const DEFAULT_DESKTOP = 'images/hero-casino-gold.svg';
    public const DEFAULT_MOBILE = 'images/hero-casino-gold-mobile.svg';

    private const DEFAULT_DESKTOP_SIZE = [1600, 720];
    private const DEFAULT_MOBILE_SIZE = [900, 1100];

    /**
     * Pixel size of the image a slot is showing, as [width, height].
     *
     * Only the ratio matters: the lobby gives the hero this height so a wide
     * banner is shown whole instead of being cropped to a fixed-height box.
     * The phone slot falls back the same way its image does.
     *
     * @return array{0: int, 1: int}
     */
    public static function size(string $slot): array
    {
        $slot = $slot === 'mobile' ? 'mobile' : 'desktop';

        if ($slot === 'mobile' && self::path('mobile') === null) {
            return self::path('desktop') !== null ? self::size('desktop') : self::DEFAULT_MOBILE_SIZE;
        }

        if (self::path($slot) === null) {
            return $slot === 'mobile' ? self::DEFAULT_MOBILE_SIZE : self::DEFAULT_DESKTOP_SIZE;
        }

        $raw = (string) PlatformSetting::valueFor($slot === 'mobile' ? self::SETTING_MOBILE_SIZE : self::SETTING_DESKTOP_SIZE, '');

        if (preg_match('/^(\d{2,5})x(\d{2,5})$/', $raw, $m) && (int) $m[1] > 0 && (int) $m[2] > 0) {
            return [(int) $m[1], (int) $m[2]];
        }

        return $slot === 'mobile' ? self::DEFAULT_MOBILE_SIZE : self::DEFAULT_DESKTOP_SIZE;
    }

    /** The stored reference for a slot, or null when nothing usable is stored. */
    public static function path(string $slot): ?string
    {
        $slot = $slot === 'mobile' ? 'mobile' : 'desktop';
        $value = trim((string) PlatformSetting::valueFor(
            $slot === 'mobile' ? self::SETTING_MOBILE : self::SETTING_DESKTOP,
            ''
        ));

        return $value !== '' && self::present($slot, $value) ? $value : null;
    }

    /** The reference as saved, usable or not — the admin uses it to say a file is missing. */
    public static function storedReference(string $slot): ?string
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

        return $path ? self::urlFor('desktop', $path) : asset(self::DEFAULT_DESKTOP);
    }

    public static function mobileUrl(): string
    {
        $path = self::path('mobile');

        if ($path) {
            return self::urlFor('mobile', $path);
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

    /** True when something is saved for the slot but its image is gone. */
    public static function isMissing(string $slot): bool
    {
        return self::storedReference($slot) !== null && self::path($slot) === null;
    }

    private static function urlFor(string $slot, string $reference): string
    {
        if (HeroImageProcessor::isMedia($reference)) {
            return route('media.hero', ['slot' => $slot, 'version' => HeroImageProcessor::versionOf($reference)], false);
        }

        return asset('storage/'.ltrim($reference, '/'));
    }

    /**
     * Whether the image behind a reference exists. Cached briefly: the lobby is
     * the busiest page and this runs for each slot on every render.
     */
    private static function present(string $slot, string $reference): bool
    {
        try {
            return (bool) Cache::remember('hero_present:'.$slot.':'.md5($reference), 120, function () use ($slot, $reference): bool {
                if (HeroImageProcessor::isMedia($reference)) {
                    return SiteMedia::available()
                        && SiteMedia::query()->where('slot', 'hero-'.$slot)->exists();
                }

                // A legacy disk upload is only usable if the file survived AND
                // the host actually exposes the public disk (symlink present).
                return Storage::disk('public')->exists($reference)
                    && is_dir(public_path('storage'));
            });
        } catch (\Throwable) {
            return false;
        }
    }
}
