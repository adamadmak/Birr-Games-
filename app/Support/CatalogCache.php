<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The lobby frontpage's shared, short-lived catalog snapshot.
 *
 * The unfiltered lobby is the most requested page on the site and its content is
 * identical for every visitor, yet each request re-read the entire available games
 * table — around two thousand hydrated models — just to keep ten per provider.
 * Under a traffic spike that one page was most of the database load, and the time
 * each request spent building it was time a worker could not serve anyone else,
 * which is exactly how the pool ran out and the gateway started answering 502.
 *
 * Sixty seconds of reuse bounds staleness for feed-driven changes, and the
 * revision key makes an operator's own edits instant: every catalog mutation bumps
 * it, orphaning all cached pages at once. Search and provider filters never touch
 * this cache — a filtered request must reflect reality, and caching per query
 * string would just fill the store with single-use entries.
 */
final class CatalogCache
{
    public const TTL_SECONDS = 60;

    /*
     * Only the first pages are worth keeping. The page number comes from the URL,
     * so without a cap ?page=999999 would mint a fresh cache entry per probe — and
     * on the file store an entry orphaned by a revision bump is never read again
     * and therefore never garbage-collected. Nobody browses to page 21 of a game
     * grid; a request that deep just builds live.
     */
    public const MAX_CACHED_PAGE = 20;

    private const REVISION_KEY = 'lobby:catalog:rev';

    public static function remember(int $page, \Closure $build): mixed
    {
        if ($page > self::MAX_CACHED_PAGE) {
            return $build();
        }

        return Cache::remember(self::key($page), now()->addSeconds(self::TTL_SECONDS), $build);
    }

    /**
     * Orphan every cached page at once, e.g. after an operator edits the catalog.
     *
     * Deliberately not deduplicated per request: an increment costs microseconds,
     * the wasChanged() guard on the model listeners already keeps clean re-saves
     * from arriving here, and any in-process memo would hold stale state in a
     * long-running runtime and make bumps order-dependent within one process.
     */
    public static function bump(): void
    {
        try {
            if (! Cache::add(self::REVISION_KEY, 1)) {
                Cache::increment(self::REVISION_KEY);
            }
        } catch (\Throwable) {
            // A failed bump only means one extra minute of the previous snapshot.
        }
    }

    /**
     * The same revision-keyed reuse for the lobby's other shared snapshots.
     *
     * The headline count and the featured card were cached under fixed keys, so
     * bump() could not orphan them and they outlived an operator's edit by their
     * whole TTL. That is invisible for a catalog sync, but it matters for the
     * QTech go-live switch, which is a kill switch: turning it off has to clear
     * the frontpage now, not in five minutes. Both are gated by
     * Game::scopeAvailable(), so both must age out with the pages they sit on.
     */
    public static function rememberAux(string $name, int $ttlSeconds, \Closure $build): mixed
    {
        return Cache::remember(
            'lobby:catalog:r'.self::revision().':'.$name,
            now()->addSeconds($ttlSeconds),
            $build,
        );
    }

    private static function key(int $page): string
    {
        return 'lobby:catalog:r'.self::revision().':p'.$page;
    }

    private static function revision(): int
    {
        return (int) Cache::get(self::REVISION_KEY, 0);
    }
}
