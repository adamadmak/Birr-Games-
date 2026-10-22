<?php

use App\Models\Game;
use App\Support\GameSearch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Two studios in the QTech catalogue ship a game called "Aviator".
 *
 * The featured list matches on title, so it marked both, and the Trending rail
 * showed Aviator twice — using one of the twelve places on a duplicate. Spribe
 * wrote the original and theirs is the one the operator meant; the other is a
 * different studio's game that happens to share the name.
 *
 * Matched on the studio rather than the slug because the slug carries a random
 * suffix and differs between the two brands, while the studio does not.
 *
 * Kept separate from the migration that built the list so that one stays a
 * faithful record of what was asked for, and this stays a readable note about
 * the one title that needed disambiguating.
 */
return new class extends Migration
{
    private const TITLE = 'Aviator';

    private const KEEP_STUDIO = 'Spribe';

    public function up(): void
    {
        $this->others()->each(function (Game $game): void {
            $game->forceFill([
                'top_game' => false,
                'manual_overrides' => array_values(array_diff($game->manual_overrides ?? [], ['top_game'])),
            ])->save();
        });
    }

    /**
     * Deliberately not reversible into "feature it again".
     *
     * Rolling this back should undo the disambiguation, and the migration
     * before it is what decides whether Aviator is featured at all. Re-marking
     * here would resurrect a duplicate that migration never intended.
     */
    public function down(): void
    {
        // Nothing to restore.
    }

    private function others()
    {
        if (! Schema::hasTable('games')) {
            return Game::query()->whereRaw('1 = 0');
        }

        return Game::query()
            ->whereRaw(GameSearch::squashedColumn('title').' = ?', [GameSearch::squash(self::TITLE)])
            ->whereHas('provider', fn ($provider) => $provider->where('name', '!=', self::KEEP_STUDIO));
    }
};
