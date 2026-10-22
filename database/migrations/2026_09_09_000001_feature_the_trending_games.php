<?php

use App\Models\Game;
use App\Support\GameSearch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The games the operator wants on the front page's Trending rail.
 *
 * Two things make this a migration rather than twelve visits to the admin.
 *
 * There is no shell on this host, so a migration is the only way to make a
 * change like this land on both brands without an operator repeating it by
 * hand — and repeating it by hand across two brands is how they drift.
 *
 * And `top_game` is a SYNC-MANAGED field: the QTech feed sends `top_game =>
 * false` for every game in the catalogue, so a tick applied in the admin — or
 * here — is wiped by the next sync unless the field is PINNED. That is what
 * `manual_overrides` is for, and why this writes the pin as well as the flag.
 * Without it the rail would look right until the next hourly refresh and then
 * quietly empty itself.
 *
 * Titles are matched on their squashed form because the feed delivers them run
 * together: "Gates of Olympus" is stored as "GatesOfOlympus", "JetX" as "jetx".
 * The match is EXACT on that squashed form, never a substring — "Multi Hot 5"
 * must not also catch "Multi Hot 5 Burn Wilds", which is a different game that
 * happens to be on the list in its own right.
 */
return new class extends Migration
{
    /** As the operator listed them. Order is theirs; it does not affect display. */
    private const FEATURED = [
        'Multi Hot 5',
        '2021 Hit Slot',
        'Aviator',
        'Aztec Gems',
        'Aztec Gems Deluxe',
        'Fortune Coins 2',
        'Gates of Olympus',
        'JetX',
        'Multi Hot 5 Burn Wilds',
        'Fortune Garuda 1000',
        'Habesha Fortune 5',
        'Multi Hot Ways',
    ];

    public function up(): void
    {
        $this->each(function (Game $game): void {
            $overrides = array_values(array_unique(array_merge($game->manual_overrides ?? [], ['top_game'])));
            $game->forceFill(['top_game' => true, 'manual_overrides' => $overrides])->save();
        });
    }

    public function down(): void
    {
        $this->each(function (Game $game): void {
            $overrides = array_values(array_diff($game->manual_overrides ?? [], ['top_game']));
            $game->forceFill(['top_game' => false, 'manual_overrides' => $overrides])->save();
        });
    }

    private function each(callable $apply): void
    {
        // A brand whose catalogue has not synced yet simply has nothing to mark.
        if (! Schema::hasTable('games')) {
            return;
        }

        $wanted = array_map([GameSearch::class, 'squash'], self::FEATURED);
        $expression = GameSearch::squashedColumn('title');

        Game::query()
            ->whereRaw($expression.' in ('.implode(',', array_fill(0, count($wanted), '?')).')', $wanted)
            ->each($apply);
    }
};
