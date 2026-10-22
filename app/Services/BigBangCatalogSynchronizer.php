<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameCategory;
use App\Models\GameLobby;
use App\Models\GameProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class BigBangCatalogSynchronizer
{
    public function __construct(private readonly BigBangCasinoClient $client)
    {
    }

    /**
     * @param  float|null  $budgetSeconds  Wall-clock ceiling for this run. A full
     * read takes minutes, and on this host the sync runs inside a web request —
     * on a small worker pool those minutes are minutes the site cannot serve
     * anyone. When the budget runs out the run stops cleanly between pages and
     * reports itself truncated, which the completeness gate below already treats
     * as "do not retire anything": upserts are idempotent, so the catalog simply
     * converges across successive timer ticks instead of in one long outage.
     * Null means unlimited, for a real cron or console run.
     */
    public function sync(?float $budgetSeconds = null): array
    {
        $deadline = $budgetSeconds !== null ? microtime(true) + $budgetSeconds : null;
        $created = 0;
        $updated = 0;
        $page = 1;
        $limit = max(1, min(500, (int) config('services.bigbang.sync_page_size', 200)));
        // Runaway guard only. The real bound is the row count the feed reports;
        // sizing the read off a fixed page count is what silently truncated it.
        $pageCeiling = max(1, (int) config('services.bigbang.max_sync_pages', 200));
        $seenGameIds = [];
        $fetched = 0;
        $expected = null;
        $truncated = false;

        do {
            if ($deadline !== null && microtime(true) >= $deadline) {
                $truncated = true;
                break;
            }

            $payload = $this->client->catalogPage($page, $limit);
            $games = $this->gamesFrom($payload);
            if ($page === 1) {
                if ($games === []) {
                    throw new RuntimeException('BigBang returned an empty or unrecognized game catalog.');
                }
                $reported = (int) Arr::get($payload, 'pagination.total', 0);
                $expected = $reported > 0 ? $reported : null;
                if ($expected !== null) {
                    $pageCeiling = max($pageCeiling, (int) ceil($expected / $limit) + 1);
                }
            }

            $fetched += count($games);
            foreach ($games as $position => $item) {
                $game = DB::transaction(fn () => $this->upsertGame($item, (($page - 1) * $limit) + $position));
                if (!$game) {
                    continue;
                }
                $seenGameIds[$game->id] = true;
                $game->wasRecentlyCreated ? $created++ : $updated++;
            }

            $hasMore = $this->hasMore($payload, $page, count($games));
            $page++;
            if ($hasMore && $page > $pageCeiling) {
                $truncated = true;
            }
        } while ($hasMore && $page <= $pageCeiling);

        $this->disableGamesOutsideProviderScope();

        // The feed is the source of truth. BigBang already filters it by this key's
        // portal settings (w_apis.blocked_providers) plus the provider's global
        // enabled flag, so anything the feed stops returning is a provider the
        // account no longer has — retire it instead of leaving it on the lobby.
        //
        // Retiring is only safe on a read we know is COMPLETE, and "complete" has to
        // be measured against the row count the feed itself reports. Judging it by a
        // page cap meant a catalog larger than the cap read as truncated on every
        // single run, so the sync only ever added: withdrawn providers stayed on the
        // lobby and every provider the feed renamed was minted as a second row.
        $complete = !$truncated && ($expected === null || $fetched >= $expected);
        $retired = $complete ? $this->retireGamesMissingFrom(array_keys($seenGameIds)) : 0;
        $providers = $complete ? $this->refreshProviderActivation() : [];

        // The retire/disable phases above are mass query-builder updates, which fire
        // no model events — so the per-save bump never sees them, and they are also
        // the LAST writes of the run. Without this, a lobby request landing after
        // the final per-row save could cache a snapshot that still shows every game
        // this run just retired, and serve it for a full TTL.
        \App\Support\CatalogCache::bump();

        return [
            'created' => $created,
            'updated' => $updated,
            'pages' => $page - 1,
            'fetched' => $fetched,
            'expected' => $expected,
            'retired' => $retired,
            'providers_disabled' => $providers['disabled'] ?? 0,
            'providers_enabled' => $providers['enabled'] ?? 0,
            'truncated' => !$complete,
        ];
    }

    /** Deactivate synced games the feed no longer returns. Local games are never touched. */
    private function retireGamesMissingFrom(array $seenIds): int
    {
        $query = Game::query()
            ->where('sync_source', 'bigbang')
            ->where(fn ($q) => $q->where('active', true)->orWhere('playable', true));

        if ($seenIds !== []) {
            $query->whereNotIn('id', $seenIds);
        }

        return $query->update(['active' => false, 'playable' => false]);
    }

    /**
     * The lobby lists providers via GameProvider::where('active', true), so a provider
     * whose games have all been retired has to be switched off too or it renders as an
     * empty tile. Only providers that carry synced games are considered — a purely
     * local provider keeps whatever the operator set by hand.
     */
    private function refreshProviderActivation(): array
    {
        $withActiveGames = Game::query()
            ->where('active', true)
            ->distinct()
            ->pluck('provider_id')
            ->filter()
            ->all();

        $syncedProviderIds = Game::query()
            ->where('sync_source', 'bigbang')
            ->distinct()
            ->pluck('provider_id')
            ->filter()
            ->all();

        if ($syncedProviderIds === []) {
            return ['enabled' => 0, 'disabled' => 0];
        }

        $enabled = GameProvider::query()
            ->whereIn('id', $syncedProviderIds)
            ->whereIn('id', $withActiveGames ?: [0])
            ->where('active', false)
            ->update(['active' => true]);

        $disabled = GameProvider::query()
            ->whereIn('id', $syncedProviderIds)
            ->whereNotIn('id', $withActiveGames ?: [0])
            ->where('active', true)
            ->update(['active' => false]);

        return ['enabled' => $enabled, 'disabled' => $disabled];
    }

    /**
     * When the account is pinned to a single studio, hide synced games from any other.
     *
     * BIGBANG_PROVIDER holds whatever the operator typed, while the feed names the studio
     * itself, so the two rarely match exactly — "bgaming" against "BGaming", a code against
     * a label. Matching on the name alone therefore resolved to nothing and the next line
     * switched off the entire synced catalogue: every game still in the database, none of
     * them findable in the lobby and none of them launchable.
     *
     * So the pin is matched on name or slug, case-insensitively, and if it still resolves to
     * no provider the catalogue is left alone. A pin nobody recognises is a configuration
     * mistake, and hiding every game is never the right answer to one.
     */
    private function disableGamesOutsideProviderScope(): void
    {
        $pin = trim((string) config('services.bigbang.provider'));
        if ($pin === '') {
            return;
        }

        $squashedPin = Str::lower(preg_replace('/[^a-z0-9]+/i', '', $pin) ?? '');

        $providerIds = GameProvider::query()
            ->get(['id', 'name', 'slug'])
            ->filter(function (GameProvider $provider) use ($pin, $squashedPin): bool {
                $candidates = [
                    Str::lower(trim((string) $provider->name)),
                    Str::lower(trim((string) $provider->slug)),
                    Str::lower(preg_replace('/[^a-z0-9]+/i', '', (string) $provider->name) ?? ''),
                    Str::lower(preg_replace('/[^a-z0-9]+/i', '', (string) $provider->slug) ?? ''),
                ];

                return in_array(Str::lower($pin), $candidates, true)
                    || ($squashedPin !== '' && in_array($squashedPin, $candidates, true));
            })
            ->pluck('id');

        if ($providerIds->isEmpty()) {
            Log::warning('BIGBANG_PROVIDER does not match any known studio, so the provider scope was not applied. Every synced game stays visible.', [
                'configured_provider' => $pin,
                'known_providers' => GameProvider::query()->pluck('name')->all(),
            ]);

            return;
        }

        Game::query()
            ->where('sync_source', 'bigbang')
            ->whereNotIn('provider_id', $providerIds)
            ->update([
                'active' => false,
                'playable' => false,
            ]);
    }

    /** Feed labels that were renamed past what normalisation can bridge. Keyed by identity key. */
    private const PROVIDER_ALIASES = [
        'novomatic' => 'novamatic',
    ];

    /**
     * Find the provider row this feed label belongs to, or create it.
     *
     * The feed identifies a provider by its display label only, and BigBang restyles
     * those ("Pragmatic Play" → "Pragmatic", "Tom Horn Gaming" → "Tomhorn"). Keying
     * the row on Str::slug() of that label meant every restyle minted a second row
     * and left the first one on the lobby, so the frontpage listed both. Matching on
     * an identity key that ignores spacing, case and a trailing Gaming/Games/Play
     * keeps a renamed provider on its existing row.
     */
    private function resolveProvider(string $providerName): GameProvider
    {
        $slug = Str::slug($providerName);
        $attributes = [
            'name' => $providerName,
            'description' => 'Casino content synchronized through BigBang.',
            'accent' => 'cyan',
            'api_base_url' => (string) config('services.bigbang.base_url'),
            'active' => true,
            'content_server_enabled' => true,
            'sync_enabled' => true,
            'last_synced_at' => now(),
        ];

        $key = $this->providerKey($providerName);
        $provider = $this->providerIndex()['slug'][$slug] ?? $this->providerIndex()['key'][$key] ?? null;

        if (!$provider) {
            $provider = GameProvider::create($attributes + ['slug' => $slug]);
            $this->providerIndex['slug'][$provider->slug] = $provider;
            $this->providerIndex['key'][$key] = $provider;
            $this->refreshedProviders[$provider->id] = true;

            return $provider;
        }

        // Written once per run, not once per game: a provider is resolved for all
        // 5,000-odd rows and this used to fire an UPDATE on every one of them.
        // Keep the row's existing slug — it is what admin links and any saved
        // provider filter already point at. Only the display name follows the feed.
        if (!isset($this->refreshedProviders[$provider->id])) {
            $provider->update($attributes);
            $this->refreshedProviders[$provider->id] = true;
        }

        return $provider;
    }

    /** @var array<int, true> provider rows already written this run */
    private array $refreshedProviders = [];

    /** @var array{slug: array<string, GameProvider>, key: array<string, GameProvider>}|null */
    private ?array $providerIndex = null;

    /**
     * Providers are resolved once per game, so this is loaded once per sync rather
     * than re-read 5,000 times. Later rows win the identity key: a higher id is the
     * newer generation of a renamed provider, and that is the row to keep feeding.
     */
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

    /** Spacing-, case- and suffix-insensitive identity for a provider display label. */
    private function providerKey(string $name): string
    {
        $tokens = preg_split('/[^a-z0-9]+/', Str::lower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while (count($tokens) > 1 && in_array(end($tokens), ['gaming', 'games', 'play'], true)) {
            array_pop($tokens);
        }

        $key = implode('', $tokens);

        return self::PROVIDER_ALIASES[$key] ?? $key;
    }

    private function gamesFrom(array $payload): array
    {
        $games = Arr::get($payload, 'data.games')
            ?? Arr::get($payload, 'games')
            ?? Arr::get($payload, 'data')
            ?? $payload;

        return is_array($games) && array_is_list($games)
            ? array_values(array_filter($games, 'is_array'))
            : [];
    }

    private function hasMore(array $payload, int $page, int $count): bool
    {
        $total = (int) Arr::get($payload, 'pagination.total', 0);
        $limit = (int) Arr::get($payload, 'pagination.limit', 0);
        $offset = (int) Arr::get($payload, 'pagination.offset', 0);
        if ($total > 0 && $limit > 0) {
            return $offset + $count < $total;
        }

        $lastPage = (int) (Arr::get($payload, 'total_pages') ?? Arr::get($payload, 'meta.last_page') ?? Arr::get($payload, 'pagination.total_pages') ?? 0);
        if ($lastPage > 0) {
            return $page < $lastPage;
        }

        $next = Arr::get($payload, 'links.next') ?? Arr::get($payload, 'pagination.next');
        return $next !== null && $next !== false && $count > 0;
    }

    private function upsertGame(array $item, int $sortOrder): ?Game
    {
        $externalId = (string) ($item['id'] ?? $item['game_id'] ?? $item['slug'] ?? $item['name'] ?? '');
        $title = trim((string) ($item['title'] ?? $item['name'] ?? ''));
        if ($externalId === '' || $title === '') {
            return null;
        }

        $providerCode = trim((string) (Arr::get($item, 'provider.code') ?? $item['provider'] ?? 'BigBang'));
        $providerName = trim((string) ($item['provider_label'] ?? Arr::get($item, 'provider.label') ?? Arr::get($item, 'provider.name') ?? $item['provider_name'] ?? $providerCode));
        $providerName = $providerName !== '' ? $providerName : 'BigBang';
        $provider = $this->resolveProvider($providerName);

        $categoryName = trim((string) (Arr::get($item, 'category.name') ?? $item['category_name'] ?? $item['category'] ?? $item['type'] ?? 'Casino'));
        $categoryName = $categoryName !== '' ? Str::headline($categoryName) : 'Casino';
        $category = GameCategory::firstOrCreate(
            ['slug' => Str::slug($categoryName)],
            ['name' => $categoryName, 'description' => 'Provider-synchronized games.', 'sort_order' => 100, 'active' => true]
        );

        $game = Game::query()->firstOrNew(['provider_id' => $provider->id, 'provider_game_id' => $externalId]);
        // creation is reported from $game->wasRecentlyCreated after save()
        $baseSlug = Str::slug($title) ?: 'bigbang-game';
        $slug = $game->slug ?: $baseSlug;
        if (!$game->exists && Game::query()->where('slug', $slug)->exists()) {
            $slug = Str::limit('bigbang-'.$baseSlug.'-'.Str::lower(Str::random(5)), 140, '');
        }

        $fromFeed = [
            'category_id' => $category->id,
            'title' => $title,
            'slug' => $slug,
            'description' => (string) ($item['description'] ?? "Play {$title} through the BigBang casino aggregator."),
            'tag' => (string) ($item['tag'] ?? ''),
            'art' => 'provider',
            'symbol' => '◆',
            'image_url' => $item['thumb'] ?? $item['thumbnail'] ?? $item['image_url'] ?? $item['image'] ?? null,
            'external_launch_code' => $externalId,
            'sync_source' => 'bigbang',
            'playable' => true,
            'top_game' => (bool) ($item['featured'] ?? $item['is_featured'] ?? false),
            'free_bet_supported' => false,
            'crash' => str_contains(Str::lower($categoryName), 'crash'),
            'content_server_enabled' => true,
            'active' => true,
            'sort_order' => $sortOrder,
        ];

        // Anything an administrator pinned in the admin stays as they left it. Without this
        // the feed's "featured" flag reset a hand-picked top game on the next refresh, so a
        // choice made in the morning was gone by the afternoon with nobody having touched it.
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
}
