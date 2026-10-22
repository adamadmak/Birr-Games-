<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Matching a game by the name a human types.
 *
 * Feed titles arrive as one run-together word — "BookOfKemet",
 * "AztecGemsDeluxe", "MultiHot5Deluxe" — while people search the way they
 * speak: "book of kemet". A plain LIKE finds nothing at all between the two,
 * and the failure is the worst kind, because it looks like an empty catalogue
 * rather than a bad query. "aztec gems" returned 0 of 33 before the lobby was
 * taught to strip separators from both sides of the comparison.
 *
 * That fix lived privately inside LobbyController, so the admin's Game Engine
 * never got it: an operator could see a game on the site, search for it by name
 * in the admin to make it a top game, and be told it did not exist. It lives
 * here now, so the two cannot drift apart again.
 */
final class GameSearch
{
    /** Reduce a term to letters and digits: "Book Of Kemet" -> "bookofkemet". */
    public static function squash(string $term): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($term));
    }

    /** The same reduction expressed in SQL, for comparing against squash(). */
    public static function squashedColumn(string $column): string
    {
        $expression = "lower({$column})";
        foreach ([' ', '_', '-', "''", '.', ':'] as $separator) {
            $expression = "replace({$expression}, '{$separator}', '')";
        }

        return $expression;
    }

    /**
     * Narrow a game query to what a person meant by `$term`.
     *
     * Title, slug and studio name, each compared both literally and with the
     * separators stripped. An empty term leaves the query untouched, so callers
     * can pipe through it unconditionally.
     */
    public static function apply(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        $squashed = self::squash($term);

        return $query->where(function (Builder $match) use ($term, $squashed): void {
            $match->where('title', 'like', '%'.$term.'%')
                ->orWhere('slug', 'like', '%'.$term.'%')
                ->orWhereHas('provider', fn (Builder $provider) => $provider->where('name', 'like', '%'.$term.'%'));

            if ($squashed === '') {
                return;
            }

            $match->orWhereRaw(self::squashedColumn('title').' like ?', ['%'.$squashed.'%'])
                ->orWhereRaw(self::squashedColumn('slug').' like ?', ['%'.$squashed.'%'])
                ->orWhereHas('provider', fn (Builder $provider) => $provider
                    ->whereRaw(self::squashedColumn('name').' like ?', ['%'.$squashed.'%']));
        });
    }
}
