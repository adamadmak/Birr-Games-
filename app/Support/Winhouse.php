<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Where the WinHouse sportsbook's configuration actually comes from.
 *
 * One resolver, because the launch token is signed in the sportsbook view and
 * verified against the same secret in WinhouseWalletController: if those two
 * ever read config differently, every launch fails a signature check with no
 * useful error anywhere.
 *
 * The order is config → platform_settings → process env → a literal bound to
 * this deployment's hostname. The literal is last and exists only because
 * AletCloud runs the build's `config:cache` without the runtime env AND
 * php-fpm does not expose the dashboard variables to getenv()/env() — so on
 * this host the first three are all empty. BigBangWalletController carries the
 * same workaround and documents the same cause.
 *
 * Binding the literal to the hostname is the part worth keeping: a copy of this
 * private repo deployed on any other domain resolves to '' and fails closed
 * instead of authenticating as Lucy Play. An unbound default in config/services
 * is exactly how the BigBang live key once made a cloned site bill this
 * customer's wallet, and that must not happen twice.
 *
 * A row in platform_settings outranks the literal, so the secrets can be moved
 * into the admin panel and rotated without a deploy. That is how any other
 * domain (vamosonebet.com has its own WinHouse account) is connected: the keys
 * are pasted under Configuration → System → WinHouse sportsbook, which writes
 * winhouse_iframe_key, winhouse_api_key, winhouse_callback_token and
 * winhouse_mode. The literal below belongs to the original account only.
 */
final class Winhouse
{
    private const HOSTS = ['www.lucychewata.com', 'lucychewata.com', 'game.app.aletcloud.com'];

    private const BOUND = [
        'api_key' => 'cec9ed52dd32d05469274fc270d16bc848340daff1c38a1708e6b8d4d2fa0e19',
        'callback_token' => '13117070358bad025431927a30e506df9f6b846b300af53a',
        'iframe_key' => 'ifr_e4047ae81d79babd7a229d9f8e9ae068e96e4331',
        'mode' => 'live',
        'currency' => 'ETB',
    ];

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
     * Where a value comes from, for the admin page: 'config', 'settings', 'env',
     * 'built-in' or '' when nothing resolves. Mirrors value()'s order.
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
        if (trim((string) (getenv('WINHOUSE_'.strtoupper($name)) ?: '')) !== '') {
            return 'env';
        }
        if (self::isOwnHost() && (self::BOUND[$name] ?? '') !== '') {
            return 'built-in';
        }

        return '';
    }

    private static function value(string $name): string
    {
        $value = trim((string) config('services.winhouse.'.$name));

        if ($value === '') {
            // Wrapped: this resolver runs from a view during the response, and a
            // settings lookup that throws (no table yet on a fresh deploy, DB
            // blip) must degrade to "not configured", not white-screen the page.
            try {
                $value = trim((string) PlatformSetting::valueFor('winhouse_'.$name, ''));
            } catch (\Throwable) {
                $value = '';
            }
        }

        if ($value === '') {
            $value = trim((string) (getenv('WINHOUSE_'.strtoupper($name)) ?: ''));
        }

        if ($value === '' && self::isOwnHost()) {
            $value = self::BOUND[$name] ?? '';
        }

        return $value;
    }

    private static function isOwnHost(): bool
    {
        // Console runs (queue workers, artisan) have no request host. They are
        // this deployment by definition, so the literal applies there too —
        // otherwise a settlement replayed from a worker would fail to sign.
        if (app()->runningInConsole()) {
            return true;
        }

        return in_array(strtolower(trim((string) request()->getHost())), self::HOSTS, true);
    }
}
