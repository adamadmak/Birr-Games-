<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use App\Models\GameProvider;
use App\Models\PlatformSetting;
use App\Models\PredictionPool;
use App\Models\Game;
use App\Models\GameRound;
use App\Support\CatalogCache;
use Illuminate\Pagination\Paginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LobbyController
{
    /**
     * Studio shelves on the unfiltered front page.
     *
     * Twelve shelves of ten is 120 tiles — a page a player scrolls through.
     * Every studio stays reachable through the filter and the catalogue grid
     * below; this only decides how many get a rail of their own up top.
     */
    private const MAX_PROVIDER_SHELVES = 12;

    /**
     * A ceiling on the curated rail, not a target.
     *
     * Every featured game is shown; this only stops a mis-click that features
     * a thousand games from rendering a thousand tiles above the fold.
     */
    private const MAX_TRENDING = 24;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $provider = trim((string) $request->query('provider'));
        $isFiltered = $search !== '' || $provider !== '';

        $page = max(1, (int) Paginator::resolveCurrentPage());

        /*
         * The unfiltered frontpage is identical for every visitor, so it is built
         * once per minute and shared; see CatalogCache for why. A filtered request
         * bypasses the cache entirely: search must reflect reality, and caching per
         * query string would only fill the store with single-use entries.
         *
         * What is cached is data, not markup — auth state and the per-user cards
         * below are applied at render time, so the snapshot is safe to hand to any
         * visitor.
         */
        if ($isFiltered) {
            [$providerOptions, $providerSections, $trendingGames, $catalogItems, $hasMore] =
                $this->assembleCatalog($search, $provider, $page);
        } else {
            [$providerOptions, $providerSections, $trendingGames, $catalogItems, $hasMore] =
                CatalogCache::remember($page, fn () => $this->assembleCatalog('', '', $page));
        }

        // The paginator is rebuilt outside the cache: it carries the request path and
        // query string, which belong to this request rather than to the snapshot.
        $catalogPage = (new Paginator($catalogItems, 60, $page, [
            'path' => $request->url(),
            'pageName' => 'page',
        ]))->hasMorePagesWhen($hasMore)->withQueryString();
        $catalogGames = $catalogPage->getCollection();

        $guestSections = collect();
        if ($isFiltered) {
            $guestSections->push((object) [
                'slug' => 'search-results',
                'name' => 'Search Results',
                'description' => number_format($catalogGames->count()).' matching games',
                'games' => $catalogGames,
            ]);
        } elseif ($trendingGames->isNotEmpty()) {
            $guestSections->push((object) [
                'slug' => 'trending',
                'name' => 'Trending',
                'description' => 'Popular games from the integrated catalog',
                'games' => $trendingGames,
            ]);
        }
        foreach ($providerSections as $providerName => $providerGames) {
            $guestSections->push((object) [
                'slug' => 'provider-'.str($providerName)->slug(),
                'name' => $providerName,
                'description' => number_format($providerGames->count()).' integrated games',
                'games' => $providerGames,
            ]);
        }

        // Only present while an operator has a pool published and open, so the
        // lobby carries nothing at all the rest of the time.
        $predictionPool = $isFiltered ? null : PredictionPool::query()->live()->first();

        return view('lobby', [
            'predictionPool' => $predictionPool,
            'predictionEntry' => $predictionPool && $request->user()
                ? $predictionPool->entries()->where('user_id', $request->user()->getKey())->first()
                : null,
            'predictionPicks' => $predictionPool ? $predictionPool->entries()->count() : 0,
            'recentGames' => $isFiltered ? collect() : $this->recentlyPlayed($request),
            'trendingGames' => $trendingGames,
            'providerSections' => $providerSections,
            'guestSections' => $guestSections,
            // name drives the option value (the card filter matches on it verbatim);
            // games_count is label-only.
            'providers' => $providerOptions,
            // A headline count, not a figure anyone acts on, but the available()
            // scope makes it two correlated subqueries on every lobby load.
            // Sixty seconds of staleness is invisible here and it is free.
            'playableGameCount' => CatalogCache::rememberAux(
                'playable_count',
                60,
                fn () => Game::query()->available()->where('playable', true)->count()
            ),
            // Identical for every visitor and changed only by catalog syncs, so a
            // short cache spares the extra scan per page view. Revision-keyed like
            // the pages: it is gated by available(), so the go-live switch has to
            // be able to clear it at once.
            'featuredGame' => CatalogCache::rememberAux(
                'featured_game',
                300,
                fn () => Game::query()->available()->whereIn('sync_source', Game::PROVIDER_SOURCES)->where('playable', true)
                    ->orderByRaw("slug = 'meow-janken' desc")->orderBy('sort_order')->first(),
            ),
            'welcomeBalance' => (int) PlatformSetting::valueFor('welcome_credits', 5),
            'catalogSearch' => $search,
            'catalogProvider' => $provider,
            'catalogPage' => $catalogPage,
        ]);
    }


    /**
     * Everything about the catalog that is the same for every visitor on this page.
     *
     * Returns [provider filter options, provider sections, trending, page items,
     * has-more flag] — plain data plus model collections, all of it serialisable,
     * because the unfiltered form of this result is cached and shared.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection, 2: \Illuminate\Support\Collection, 3: \Illuminate\Support\Collection, 4: bool}
     */
    private function assembleCatalog(string $search, string $provider, int $page): array
    {
        $isFiltered = $search !== '' || $provider !== '';

        $baseQuery = Game::query()->with('provider')->available();

        // Every provider the lobby is allowed to surface right now. Derived from the
        // games rather than from the provider flag alone, so a provider left with no
        // showable game disappears from the filter as well as from the page — and
        // reappears on its own the moment its games or its category come back.
        $activeProviders = GameProvider::query()
            ->where('active', true)
            ->whereHas('games', fn ($query) => $query->available())
            // The filter shows how many games sit behind each studio, so the count has
            // to be the provider's whole showable catalog — not the 10 the frontpage
            // rail happens to render.
            ->withCount(['games' => fn ($query) => $query->available()])
            ->orderBy('name')
            ->get();

        $catalogPage = (clone $baseQuery)
            ->when($search !== '', fn ($query) => \App\Support\GameSearch::apply($query, $search))
            ->when($provider !== '', fn ($query) => $query->whereHas('provider', fn ($providerQuery) => $providerQuery->where('name', $provider)))
            ->orderByDesc('top_game')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->simplePaginate(60, ['*'], 'page', $page);
        $catalogGames = $catalogPage->getCollection();

        /*
         * Trending is the operator's own rail, not a sample of the catalogue.
         *
         * It used to take the first ten after sorting featured games to the
         * front, which quietly truncated a list someone had chosen
         * deliberately: twelve games marked, ten shown, and no way to tell
         * which two had been dropped or why. It now shows every featured game,
         * and falls back to the first ten of the catalogue only when nothing
         * is featured at all, so the rail is never empty on a fresh install.
         */
        $pool = $isFiltered ? collect() : $catalogGames;
        $featured = $pool->where('top_game', true)->sortBy('sort_order')->take(self::MAX_TRENDING)->values();
        $trendingGames = $featured->isNotEmpty()
            ? $featured
            : $pool->sortBy([['top_game', 'desc'], ['sort_order', 'asc']])->take(10)->values();

        if ($isFiltered) {
            // Search / provider filter: group whatever the catalog page returned.
            $providerSections = $catalogGames
                ->groupBy(fn (Game $game) => $game->provider->name)
                ->sortByDesc(fn ($games) => $games->count());
        } else {
            // Frontpage: EVERY provider that has available games, shown with exactly
            // 10 of them (5 columns × 2 rows). A provider whose games are all hidden —
            // switched off, or sitting in a disabled category — yields nothing here and
            // so drops off the page entirely instead of rendering an empty rail.
            //
            // Read in one pass and grouped in memory. A query per provider meant a round
            // trip for every studio on the busiest page of the site.
            // Only ten games per provider are ever rendered, so only ten are
            // fetched. Previously every available game was hydrated into a model
            // and all but ten per studio thrown away — on a catalogue of ~2,000
            // that is the single heaviest thing on the busiest page of the site.
            // Capped. One shelf per studio was right at four studios; QTech's
            // catalogue brought a hundred and nineteen, which rendered 1,135
            // tiles and a page nobody reaches the bottom of. The biggest
            // studios lead, and the filter above still offers every one of
            // them — this bounds the FRONT page, not the catalogue.
            $providerSections = $this->gamesPerProvider(
                $activeProviders->sortByDesc('games_count')->take(self::MAX_PROVIDER_SHELVES)->values(),
                10,
            );
        }


        return [
            $activeProviders->map(fn (GameProvider $providerRow) => (object) [
                'name' => $providerRow->name,
                'games_count' => (int) $providerRow->games_count,
            ])->values(),
            $providerSections,
            $trendingGames,
            $catalogGames,
            $catalogPage->hasMorePages(),
        ];
    }

    /**
     * The player's own last-played games, most recent first.
     *
     * Rounds store the game slug, so this collapses a player's rounds to one
     * row per game and re-hydrates them from the catalog — which also drops any
     * game that has since been switched off or hidden, because `available()`
     * filters those out.
     *
     * Bounded to a 30-day window: it keeps "recent" honest, and it lets the
     * (created_at, user_id) index do the work instead of walking a player's
     * entire history on the busiest page of the site.
     */
    private function recentlyPlayed(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return collect();
        }

        // One row per game with the moment it was last played, so the rail can
        // show "2h" without a second query.
        $played = GameRound::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('game')
            ->orderByRaw('MAX(created_at) desc')
            ->limit(12)
            ->pluck(DB::raw('MAX(created_at) as last_played_at'), 'game');

        if ($played->isEmpty()) {
            return collect();
        }

        $slugs = $played->keys()->all();
        $order = array_flip($slugs);

        return Game::query()
            ->with('provider')
            ->available()
            ->whereIn('slug', $slugs)
            ->get()
            ->sortBy(fn (Game $game) => $order[$game->slug] ?? PHP_INT_MAX)
            ->values()
            ->each(function (Game $game) use ($played) {
                $game->last_played_at = Carbon::parse($played[$game->slug]);
            });
    }


    /**
     * The first $perProvider available games for each active provider.
     *
     * Uses a window function to let the database do the per-provider limiting,
     * which is the difference between reading ten rows per studio and reading
     * the whole catalogue. Falls back to the previous read-everything-and-group
     * approach wherever window functions are not available, so behaviour is
     * identical either way — only the cost differs.
     *
     * @param  \Illuminate\Support\Collection<int, GameProvider>  $providers
     */
    private function gamesPerProvider($providers, int $perProvider)
    {
        $providerIds = $providers->pluck('id')->all();

        if (empty($providerIds)) {
            return collect();
        }

        // Keyed once: the old code ran a linear firstWhere() per game, which is
        // O(games x providers) on a page that already had plenty to do.
        $byId = $providers->keyBy('id');

        $games = $this->windowedGames($providerIds, $perProvider)
            ?? Game::query()
                ->available()
                ->whereIn('provider_id', $providerIds)
                ->orderByDesc('top_game')->orderBy('sort_order')->orderBy('id')
                ->get();

        return $games
            ->each(fn (Game $game) => $game->setRelation('provider', $byId->get($game->provider_id)))
            ->groupBy('provider_id')
            ->mapWithKeys(function ($games, $providerId) use ($byId, $perProvider) {
                $provider = $byId->get($providerId);

                return $provider ? [$provider->name => $games->take($perProvider)] : [];
            })
            ->sortByDesc(fn ($games) => $games->count());
    }

    /**
     * Window-function fast path. Returns null when the database cannot do it,
     * or if the query fails for any reason, so the caller falls back rather
     * than the lobby breaking.
     */
    private function windowedGames(array $providerIds, int $perProvider)
    {
        if (! $this->supportsWindowFunctions()) {
            return null;
        }

        try {
            $inner = Game::query()
                ->available()
                ->whereIn('provider_id', $providerIds)
                ->select('games.*')
                ->selectRaw(
                    'ROW_NUMBER() OVER (PARTITION BY provider_id ORDER BY top_game DESC, sort_order ASC, id ASC) AS provider_rank'
                );

            return Game::query()
                ->fromSub($inner, 'ranked')
                ->where('provider_rank', '<=', $perProvider)
                ->orderByDesc('top_game')->orderBy('sort_order')->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** ROW_NUMBER() needs MySQL 8, MariaDB 10.2, SQLite 3.25 or any Postgres. */
    private function supportsWindowFunctions(): bool
    {
        static $supported = null;

        if ($supported !== null) {
            return $supported;
        }

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            $version = (string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);

            $supported = match ($driver) {
                'pgsql' => true,
                'sqlite' => version_compare($version, '3.25', '>='),
                'mysql' => stripos($version, 'mariadb') !== false
                    ? version_compare($this->numericVersion($version), '10.2', '>=')
                    : version_compare($this->numericVersion($version), '8.0', '>='),
                default => false,
            };
        } catch (\Throwable $e) {
            $supported = false;
        }

        return $supported;
    }

    /** "10.4.11-MariaDB-1:10.4" -> "10.4.11" */
    private function numericVersion(string $version): string
    {
        preg_match('/\d+(?:\.\d+){0,2}/', $version, $matches);

        return $matches[0] ?? '0';
    }

    /** Reduce a search term to the letters and digits, so spacing never decides a match. */
    public function promotions(): View
    {
        return view('pages.promotions', [
            'promotions' => Promotion::query()
                ->where('active', true)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }
}
