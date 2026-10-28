<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * The address the site is reached at, for links that leave it: Telegram
 * buttons, agent alerts, password-reset emails.
 *
 * On 28 Oct 2026 the host's environment still named the app's old platform
 * subdomain as TELEGRAM_APP_URL and APP_URL, so the bot could open the site on
 * an address WinHouse does not allow its book to be framed on: the mini app
 * showed no sportsbook. The operator sets the public address once on
 * Configuration → System; until then the address of the current request is
 * used, and only outside a request does it fall back to config.
 */
final class SiteUrl
{
    public const SETTING = 'public_site_url';

    public static function base(): string
    {
        $configured = self::clean((string) PlatformSetting::valueFor(self::SETTING, ''));
        if ($configured !== '') {
            return $configured;
        }

        if (! app()->runningInConsole() && app()->bound('request')) {
            $host = self::clean(request()->getSchemeAndHttpHost());
            if ($host !== '') {
                return $host;
            }
        }

        return self::clean((string) config('app.url')) ?: rtrim((string) url('/'), '/');
    }

    /** An absolute link to a named route on the public address. */
    public static function route(string $name, array $parameters = []): string
    {
        return self::base().route($name, $parameters, false);
    }

    /** `https://host` or `http://host:port`, no path, no trailing slash; '' when it is not that. */
    public static function clean(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || ! preg_match('#^https?://[A-Za-z0-9.-]+(:\d{2,5})?$#', $url)) {
            return '';
        }

        return $url;
    }
}
