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
 * Three images, all optional: a wide one for the lobby on desktop and
 * tablet, a taller one for the lobby on phones, and the band across the top
 * of the sports front page. Each falls back to the bundled artwork, and the
 * mobile slot falls back to the desktop image before that, so a brand that
 * uploads one lobby picture gets it everywhere.
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
    public const SETTING_SPORTS = 'hero_image_sports';
    public const SETTING_ALT = 'hero_image_alt';

    /** "WIDTHxHEIGHT" of what was uploaded, so the lobby can size the hero to the picture. */
    public const SETTING_DESKTOP_SIZE = 'hero_image_desktop_size';
    public const SETTING_MOBILE_SIZE = 'hero_image_mobile_size';
    public const SETTING_SPORTS_SIZE = 'hero_image_sports_size';

    public const DEFAULT_DESKTOP = 'images/hero-casino-gold.svg';
    public const DEFAULT_MOBILE = 'images/hero-casino-gold-mobile.svg';
    public const DEFAULT_SPORTS = 'images/hero-sports.svg';

    private const DEFAULT_DESKTOP_SIZE = [1600, 720];
    private const DEFAULT_MOBILE_SIZE = [900, 1100];
    private const DEFAULT_SPORTS_SIZE = [1600, 260];

    /** @var list<string> */
    public const SLOTS = ['desktop', 'mobile', 'sports'];

    /** A slot name as stored, anything unknown reading as the desktop slot. */
    public static function slot(string $slot): string
    {
        return in_array($slot, self::SLOTS, true) ? $slot : 'desktop';
    }

    public static function settingKey(string $slot): string
    {
        return match (self::slot($slot)) { 'mobile' => self::SETTING_MOBILE, 'sports' => self::SETTING_SPORTS, default => self::SETTING_DESKTOP };
    }

    public static function sizeKey(string $slot): string
    {
        return match (self::slot($slot)) { 'mobile' => self::SETTING_MOBILE_SIZE, 'sports' => self::SETTING_SPORTS_SIZE, default => self::SETTING_DESKTOP_SIZE };
    }

    /** @return array{0: int, 1: int} */
    private static function defaultSize(string $slot): array
    {
        return match (self::slot($slot)) { 'mobile' => self::DEFAULT_MOBILE_SIZE, 'sports' => self::DEFAULT_SPORTS_SIZE, default => self::DEFAULT_DESKTOP_SIZE };
    }

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
        $slot = self::slot($slot);

        if ($slot === 'mobile' && self::path('mobile') === null) {
            return self::path('desktop') !== null ? self::size('desktop') : self::DEFAULT_MOBILE_SIZE;
        }

        if (self::path($slot) === null) {
            return self::defaultSize($slot);
        }

        $raw = (string) PlatformSetting::valueFor(self::sizeKey($slot), '');

        if (preg_match('/^(\d{2,5})x(\d{2,5})$/', $raw, $m) && (int) $m[1] > 0 && (int) $m[2] > 0) {
            return [(int) $m[1], (int) $m[2]];
        }

        return self::defaultSize($slot);
    }

    /** The stored reference for a slot, or null when nothing usable is stored. */
    public static function path(string $slot): ?string
    {
        $slot = self::slot($slot);
        $value = trim((string) PlatformSetting::valueFor(self::settingKey($slot), ''));

        return $value !== '' && self::present($slot, $value) ? $value : null;
    }

    /** The reference as saved, usable or not — the admin uses it to say a file is missing. */
    public static function storedReference(string $slot): ?string
    {
        $value = trim((string) PlatformSetting::valueFor(self::settingKey($slot), ''));

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

    /** The band across the top of the sports front page; the drawn stadium until an operator uploads one. */
    public static function sportsUrl(): string
    {
        $path = self::path('sports');

        return $path ? self::urlFor('sports', $path) : asset(self::DEFAULT_SPORTS);
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
