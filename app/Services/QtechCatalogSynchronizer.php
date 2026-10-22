<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameCategory;
use App\Models\GameLobby;
use App\Models\GameProvider;
use App\Support\CatalogCache;
use App\Support\Qtech;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pulls QTech's game list into the local catalog.
 *
 * The QTech counterpart of BigBangCatalogSynchronizer and deliberately the same
 * shape: every game is upserted under sync_source = 'qtech', the feed is the
 * source of truth for what the account carries, and anything a complete read
 * no longer returns is retired rather than left on the lobby. Provider rows
 * are shared with the BigBang sync — a studio both aggregators carry is one
 * tile, and each game's own sync_source says which launcher opens it.
 */
class QtechCatalogSynchronizer
{
    public function __construct(private readonly QtechClient $client)
    {
    }

    /**
     * @param  float|null  $budgetSeconds  Wall-clock ceiling. This runs inside
     * a web request on a small worker pool; a run that overruns its budget
     * stops cleanly between pages and reports itself truncated, which the
     * completeness gate below treats as "retire nothing". Upserts are
     * idempotent, so the catalog converges across runs.
     */
    /**
     * Where a budgeted run left off, so the next one continues instead of
     * starting over. QTech's ETB list is close to ten thousand games and a
     * 45-second run gets through about three thousand; without this, every
     * run re-read the same first pages and the rest of the catalogue was
     * never reached at all.
     */
    private const CURSOR_KEY = 'qtech-catalog-sync:cursor';

    /** When the current chain of runs began — the watermark retirement uses. */
    private const CHAIN_STARTED_KEY = 'qtech-catalog-sync:chain-started';

    public function sync(?float $budgetSeconds = null): array
    {
        $deadline = $budgetSeconds !== null ? microtime(true) + $budgetSeconds : null;
        $created = 0;
        $updated = 0;
        $pages = 0;
        $fetched = 0;
        $expected = null;
        $truncated = false;
        // Runaway guard only; the feed's own cursor decides when it ends.
        $pageCeiling = 50;

        // Resume a chain if one is open, otherwise start a fresh one and note
        // when: everything the chain touches gets a newer last_synced_at than
        // this, which is how the retirement at the end knows what the feed no
        // longer carries without any single run having to see the whole list.
        $next = Cache::get(self::CURSOR_KEY);
        $resumed = is_string($next) && $next !== '';
        if (! $resumed) {
            $next = null;
            Cache::put(self::CHAIN_STARTED_KEY, now()->toDateTimeString(), now()->addDay());
        }
        $chainStartedAt = Cache::get(self::CHAIN_STARTED_KEY) ?? now()->toDateTimeString();

        do {
            if ($deadline !== null && microtime(true) >= $deadline) {
                $truncated = true;
                break;
            }

            try {
                $page = $this->client->gameListPage($next);
            } catch (\Throwable $exception) {
                // A stored cursor the feed no longer honours would fail every
                // run forever. Drop it so the next run starts a fresh chain.
                if ($resumed && $pages === 0) {
                    Cache::forget(self::CURSOR_KEY);
                }
                throw $exception;
            }
            $items = $page['items'];

            if ($pages === 0 && ! $resumed) {
                if ($items === []) {
                    throw new RuntimeException('QTech returned an empty game list for '.Qtech::currency().'.');
                }
            }
            if ($page['total'] !== null && $page['total'] > 0) {
                $expected = $page['total'];
            }

            foreach ($items as $position => $item) {
                $game = DB::transaction(fn () => $this->upsertGame($item, $fetched + $position));
                if (! $game) {
                    continue;
                }
                $game->wasRecentlyCreated ? $created++ : $updated++;
            }

            $fetched += count($items);
            $next = $page['next'];
            $pages++;

            if ($next !== null && $pages >= $pageCeiling) {
                $truncated = true;
                break;
            }
        } while ($next !== null);

        if ($truncated && $next !== null) {
            // Hand the cursor to the next run. A day is far longer than any
            // chain takes; it only stops a forgotten cursor living forever.
            Cache::put(self::CURSOR_KEY, $next, now()->addDay());
        } else {
            Cache::forget(self::CURSOR_KEY);
        }

        // Retiring is only safe once a CHAIN has reached the feed's end: the
        // games it no longer carries are exactly those this chain never
        // touched, i.e. whose last_synced_at predates the chain's start.
        $complete = ! $truncated && $next === null;
        $retired = $complete ? $this->retireGamesNotSyncedSince($chainStartedAt) : 0;
        $providers = $complete ? $this->refreshProviderActivation() : ['enabled' => 0, 'disabled' => 0];
        if ($complete) {
            Cache::forget(self::CHAIN_STARTED_KEY);
        }

        // The retire phase is a mass update that fires no model events, and it
        // is the last write of the run — bump the lobby cache by hand or a
        // request landing now could pin a snapshot of the pre-retire catalog.
        CatalogCache::bump();

        return [
            'created' => $created,
            'updated' => $updated,
            'pages' => $pages,
            'fetched' => $fetched,
            'expected' => $expected,
            'retired' => $retired,
            'providers_disabled' => $providers['disabled'],
            'providers_enabled' => $providers['enabled'],
            'truncated' => ! $complete,
            'resumed' => $resumed,
            'will_resume' => $truncated && $next !== null,
        ];
    }

    /** Deactivate QTech games the finished chain never touched. */
    private function retireGamesNotSyncedSince(string $chainStartedAt): int
    {
        return Game::query()
            ->where('sync_source', 'qtech')
            ->where(fn ($q) => $q->where('active', true)->orWhere('playable', true))
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $chainStartedAt))
            ->update(['active' => false, 'playable' => false]);
    }

    /**
     * A provider whose games have all been retired must be switched off too or
     * the lobby renders an empty tile. Only providers carrying QTech games are
     * considered; the BigBang sync tends to its own, and a provider both carry
     * stays on while EITHER has an active game for it.
     */
    private function refreshProviderActivation(): array
    {
        $withActiveGames = Game::query()->where('active', true)->distinct()->pluck('provider_id')->filter()->all();
        $qtechProviderIds = Game::query()->where('sync_source', 'qtech')->distinct()->pluck('provider_id')->filter()->all();

        if ($qtechProviderIds === []) {
            return ['enabled' => 0, 'disabled' => 0];
        }

        $enabled = GameProvider::query()
            ->whereIn('id', $qtechProviderIds)
            ->whereIn('id', $withActiveGames ?: [0])
            ->where('active', false)
            ->update(['active' => true]);

        $disabled = GameProvider::query()
            ->whereIn('id', $qtechProviderIds)
            ->whereNotIn('id', $withActiveGames ?: [0])
            ->where('active', true)
            ->update(['active' => false]);

        return ['enabled' => $enabled, 'disabled' => $disabled];
    }

    private function upsertGame(array $item, int $sortOrder): ?Game
    {
        $externalId = trim((string) ($item['id'] ?? ''));
        $title = trim((string) ($item['name'] ?? ''));
        if ($externalId === '' || $title === '') {
            return null;
        }

        $providerName = trim((string) (data_get($item, 'provider.name') ?: data_get($item, 'provider.id') ?: 'QTech'));
        $provider = $this->resolveProvider($providerName !== '' ? $providerName : 'QTech');

        $categoryName = $this->categoryName((string) ($item['category'] ?? ''));
        $category = GameCategory::firstOrCreate(
            ['slug' => Str::slug($categoryName)],
            ['name' => $categoryName, 'description' => 'Provider-synchronized games.', 'sort_order' => 100, 'active' => true]
        );

        $game = Game::query()->firstOrNew(['provider_id' => $provider->id, 'provider_game_id' => $externalId]);
        $baseSlug = Str::slug($title) ?: 'qtech-game';
        $slug = $game->slug ?: $baseSlug;
        if (! $game->exists && Game::query()->where('slug', $slug)->exists()) {
            $slug = Str::limit('qtech-'.$baseSlug.'-'.Str::lower(Str::random(5)), 140, '');
        }

        // HTML5 is the only client type a browser (or the Telegram web view)
        // can open. Anything else in the feed is listed but not launchable.
        $clientTypes = array_map('strtoupper', array_filter((array) ($item['clientTypes'] ?? []), 'is_string'));
        $playable = $clientTypes === [] || in_array('HTML5', $clientTypes, true);

        $fromFeed = [
            'category_id' => $category->id,
            'title' => $title,
            'slug' => $slug,
            'description' => trim((string) ($item['description'] ?? '')) ?: "Play {$title} through QTech Games.",
            'tag' => '',
            'art' => 'provider',
            'symbol' => '◆',
            'image_url' => $this->image($item),
            'external_launch_code' => $externalId,
            'sync_source' => 'qtech',
            'playable' => $playable,
            'top_game' => false,
            'free_bet_supported' => false,
            'crash' => str_contains(Str::lower($categoryName), 'crash'),
            'content_server_enabled' => true,
            'active' => true,
            'sort_order' => $sortOrder,
        ];

        // Anything an administrator pinned in the admin stays as they left it.
        foreach ($game->pinnedFields() as $pinned) {
            unset($fromFeed[$pinned]);
        }

        $game->fill($fromFeed + ['last_synced_at' => now()]);
        $game->save();

        $lobby = GameLobby::firstOrCreate(
            ['slug' => Str::slug($categoryName)],
            ['name' => $categoryName, 'description' => "{$categoryName} games", 'sort_order' => $category->sort_order, 'active' => true]
        );
        $game->lobbies()->syncWithoutDetaching([$lobby->id => ['sort_order' => $sortOrder]]);

        return $game;
    }

    /**
     * QTech describe a game's category as a path — "CASINO/SLOT/5REEL" — and
     * the middle segment is the one players recognise.
     */
    private function categoryName(string $path): string
    {
        $segments = array_values(array_filter(array_map('trim', explode('/', strtoupper($path)))));
        $code = $segments[1] ?? $segments[0] ?? 'CASINO';

        return match ($code) {
            'SLOT' => 'Slots',
            'LIVECASINO' => 'Live Casino',
            'TABLEGAME' => 'Table Games',
            'VIDEOPOKER' => 'Video Poker',
            'INSTANTWIN' => 'Instant Win',
            'SCRATCHCARD' => 'Scratch Cards',
            'CASUALGAME' => 'Casual Games',
            'GAME_SHOW' => 'Game Shows',
            'VIRTUAL_SPORTS', 'VIRTUALGAME' => 'Virtual Sports',
            'CRASH' => 'Crash',
            'BINGO' => 'Bingo',
            'LOTTERY' => 'Lottery',
            'SHOOTING' => 'Shooting',
            'ESPORTS' => 'Esports',
            'SPORTS' => 'Sports',
            default => Str::headline(Str::lower($code)),
        };
    }

    /** The square logo is the card art; fall back through the other shapes. */
    private function image(array $item): ?string
    {
        $images = collect((array) ($item['images'] ?? []))
            ->filter(fn ($image) => is_array($image) && filled($image['url'] ?? null))
            ->keyBy(fn (array $image) => strtolower((string) ($image['type'] ?? '')));

        foreach (['logo-square', 'logo-round', 'banner'] as $type) {
            if ($images->has($type)) {
                return (string) $images[$type]['url'];
            }
        }

        return $images->first()['url'] ?? null;
    }

    /** @var array<int, true> provider rows already refreshed this run */
    private array $refreshedProviders = [];

    /** @var array{slug: array<string, GameProvider>, key: array<string, GameProvider>}|null */
    private ?array $providerIndex = null;

    /**
     * Find the provider row a feed label belongs to, or create it. Same
     * identity rule as the BigBang sync — spacing, case and a trailing
     * Gaming/Games/Play ignored — so "Pragmatic Play" from QTech lands on
     * the row "Pragmatic" from BigBang already made, not beside it.
     */
    private function resolveProvider(string $providerName): GameProvider
    {
        $slug = Str::slug($providerName);
        $key = $this->providerKey($providerName);
        $provider = $this->providerIndex()['slug'][$slug] ?? $this->providerIndex()['key'][$key] ?? null;

        $attributes = [
            'name' => $providerName,
            'active' => true,
            'content_server_enabled' => true,
            'sync_enabled' => true,
            'last_synced_at' => now(),
        ];

        if (! $provider) {
            $provider = GameProvider::create($attributes + [
                'slug' => $slug,
                'description' => 'Casino content synchronized through QTech Games.',
                'accent' => 'emerald',
                'api_base_url' => Qtech::apiBaseUrl(),
            ]);
            $this->providerIndex['slug'][$provider->slug] = $provider;
            $this->providerIndex['key'][$key] = $provider;
            $this->refreshedProviders[$provider->id] = true;

            return $provider;
        }

        // Once per run, not once per game; and only the fields that mean
        // "this provider is live" — a row BigBang also feeds keeps its own
        // description and base URL.
        if (! isset($this->refreshedProviders[$provider->id])) {
            $provider->update($attributes);
            $this->refreshedProviders[$provider->id] = true;
        }

        return $provider;
    }

    private function providerIndex(): array
    {
        if ($this->providerIndex !== null) {
            return $this->providerIndex;
        }

        $this->providerIndex = ['slug' => [], 'key' => []];
        foreach (GameProvider::query()->orderBy('id')->get() as $provider) {
            $this->providerIndex['slug'][$provider->slug] = $provider;
            $this->providerIndex['key'][$this->providerKey((string) $provider->name)] = $provider;
        }

        return $this->providerIndex;
    }

    private function providerKey(string $name): string
    {
        $tokens = preg_split('/[^a-z0-9]+/', Str::lower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while (count($tokens) > 1 && in_array(end($tokens), ['gaming', 'games', 'play'], true)) {
            array_pop($tokens);
        }

        return implode('', $tokens);
    }
}
