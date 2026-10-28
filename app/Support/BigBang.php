<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * BigBang: the retirement switch, and where its secrets come from.
 *
 * WHY RETIREMENT IS A CODE GATE AND NOT AN ADMIN TOGGLE ON THE ROWS
 *
 * The obvious retirement — switch the games and providers off in the admin —
 * does not hold, for two independent reasons, and both were measured rather
 * than guessed:
 *
 *  - BigBang's own catalogue sync writes `active => true` and
 *    `playable => true` for every game the feed still returns. Three entry
 *    points can trigger it: the hourly schedule, the external timer's HTTP
 *    endpoint, and the admin Sync button.
 *
 *  - Provider rows are SHARED between the two aggregators. QTech's own hourly
 *    sync sets `active => true` on any provider it touches, so a provider
 *    switched off to hide BigBang is switched back on by the sync that has to
 *    keep running.
 *
 * So a data-level retirement is undone within the hour, twice over, and the
 * games quietly reappear in the lobby. Gating at the query instead makes the
 * row state irrelevant: whatever the sync writes, nothing reaches a player.
 *
 * WHAT THIS DELIBERATELY DOES NOT TOUCH
 *
 * The wallet callback. Stopping BigBang from reaching players is not the same
 * as stopping BigBang money: a bet already placed still has to pay out, and
 * sessions live up to six hours. `BigBangWalletController` keeps answering so
 * that a player mid-spin is paid.
 *
 * SECRETS
 *
 * The API key (which also signs every balance-change callback), the callback
 * token WinHouse-style `?token=` checks compare against, and the sync token
 * the external timer presents all resolve config → platform_settings →
 * process env → the .env file (App\Support\RuntimeEnv). On AletCloud the
 * first and third are empty (the build caches config without the runtime
 * env), so the values pasted under Configuration → System → BigBang are what
 * answer. Until 28 Oct 2026 the API key and sync token were literal defaults
 * in config/services.php and the callback token a literal in the wallet
 * controller; all three must be rotated at BigBang now that they have been
 * in the repository.
 */
final class BigBang
{
    public const SETTING_API_KEY = 'bigbang_api_key';
    public const SETTING_CALLBACK_TOKEN = 'bigbang_callback_token';
    public const SETTING_SYNC_TOKEN = 'bigbang_sync_token';

    public static function retired(): bool
    {
        try {
            return (bool) PlatformSetting::valueFor('bigbang_retired', true);
        } catch (\Throwable) {
            // A settings lookup that throws must not resurrect a retired
            // aggregator, so this fails toward "off" — the opposite direction
            // from Qtech::liveToPlayers(), and for the same reason: neither
            // failure may put a game in front of a player by accident.
            return true;
        }
    }

    /** Authenticates our calls to BigBang and signs their balance-change callbacks. */
    public static function apiKey(): string
    {
        return RuntimeEnv::get('services.bigbang.api_key', self::SETTING_API_KEY, 'BIGBANG_API_KEY');
    }

    /** The `?token=` BigBang send on every wallet callback; the same value sits in their portal's wallet URLs. */
    public static function callbackToken(): string
    {
        return RuntimeEnv::get('services.bigbang.callback_token', self::SETTING_CALLBACK_TOKEN, 'BIGBANG_CALLBACK_TOKEN');
    }

    /** Guards the catalogue-refresh endpoint the external timer calls. */
    public static function syncToken(): string
    {
        return RuntimeEnv::get('services.bigbang.sync_token', self::SETTING_SYNC_TOKEN, 'BIGBANG_SYNC_TOKEN');
    }
}
