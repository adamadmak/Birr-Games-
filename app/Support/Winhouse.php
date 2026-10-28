<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Where the WinHouse sportsbook's configuration actually comes from.
 *
 * One resolver, because the launch token is signed on the front page and
 * verified against the same secret in WinhouseWalletController: if those two
 * ever read config differently, every launch fails a signature check with no
 * useful error anywhere.
 *
 * The order is config → platform_settings → process env → the .env file on
 * disk (App\Support\RuntimeEnv). AletCloud runs the build's `config:cache`
 * without the runtime env and php-fpm does not expose the dashboard variables
 * to getenv()/env(), so on that host config and the process env are empty and
 * platform_settings is what answers: the keys are pasted under
 * Configuration → System → WinHouse sportsbook, which writes
 * winhouse_iframe_key, winhouse_api_key, winhouse_callback_token and
 * winhouse_mode. They can be rotated there without a deploy.
 *
 * Until 28 Oct 2026 this class also carried the original account's keys as a
 * literal bound to the lucychewata.com hostname. Those keys authorise money
 * (the API key signs wins), the binding trusted the client-sent Host header,
 * and anyone holding the repository held the keys. The literal is gone and
 * those keys must be rotated at WinHouse; this resolver never falls back to
 * anything that is not an operator's own setting.
 */
final class Winhouse
{
    public static function apiKey(): string
    {
        return self::value('api_key');
    }

    public static function callbackToken(): string
    {
        return self::value('callback_token');
    }

    public static function iframeKey(): string
    {
        return self::value('iframe_key');
    }

    public static function mode(): string
    {
        return self::value('mode') ?: 'sandbox';
    }

    public static function currency(): string
    {
        return strtoupper(self::value('currency') ?: 'ETB');
    }

    public static function isLive(): bool
    {
        return self::mode() === 'live';
    }

    /** All three keys resolve; without any one of them the book cannot settle a bet. */
    public static function configured(): bool
    {
        return self::iframeKey() !== '' && self::apiKey() !== '' && self::callbackToken() !== '';
    }

    /**
     * Where a value comes from, for the admin page: 'config', 'settings',
     * 'env' or '' when nothing resolves. Mirrors value()'s order.
     */
    public static function source(string $name): string
    {
        if (trim((string) config('services.winhouse.'.$name)) !== '') {
            return 'config';
        }
        try {
            if (trim((string) PlatformSetting::valueFor('winhouse_'.$name, '')) !== '') {
                return 'settings';
            }
        } catch (\Throwable) {
            // not configured, same as value()
        }
        $env = 'WINHOUSE_'.strtoupper($name);
        if (trim((string) (getenv($env) ?: '')) !== '' || RuntimeEnv::fromEnvFile($env) !== '') {
            return 'env';
        }

        return '';
    }

    private static function value(string $name): string
    {
        // RuntimeEnv wraps the settings lookup: this resolver runs from a view
        // during the response, and a settings table that is not there yet must
        // read as "not configured", not white-screen the page.
        return RuntimeEnv::get('services.winhouse.'.$name, 'winhouse_'.$name, 'WINHOUSE_'.strtoupper($name));
    }
}
