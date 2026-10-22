<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * BigBang is retired. This is the switch that holds it retired.
 *
 * WHY THIS IS A CODE GATE AND NOT AN ADMIN TOGGLE ON THE ROWS
 *
 * The obvious retirement — switch the games and providers off in the admin —
 * does not hold, for two independent reasons, and both were measured rather
 * than guessed:
 *
 *  - BigBang's own catalogue sync writes `active => true` and
 *    `playable => true` for every game the feed still returns, and it cannot be
 *    disarmed by clearing environment variables, because this platform builds
 *    its config cache without the runtime environment and the credentials are
 *    therefore committed as literal defaults. Three entry points can trigger
 *    it: the hourly schedule, the external timer's HTTP endpoint, and the admin
 *    Sync button.
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
 * that a player mid-spin is paid, and comes out in a separate change once
 * nothing is in flight. Retiring and settling are different dates.
 *
 * Default: retired. An operator who needs BigBang back turns it back on in
 * Settings, and a lookup that fails reads as retired rather than as live.
 */
final class BigBang
{
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
}
