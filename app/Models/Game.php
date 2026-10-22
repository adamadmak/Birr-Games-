<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Game extends Model
{
    protected $fillable = ['provider_id', 'category_id', 'provider_game_id', 'title', 'slug', 'description', 'tag', 'label', 'label_background_color', 'art', 'symbol', 'image_url', 'image_path', 'agent', 'external_launch_code', 'sync_source', 'manual_overrides', 'playable', 'top_game', 'free_bet_supported', 'crash', 'content_server_enabled', 'active', 'sort_order', 'last_synced_at'];

    protected function casts(): array
    {
        return ['playable' => 'boolean', 'top_game' => 'boolean', 'free_bet_supported' => 'boolean', 'crash' => 'boolean', 'content_server_enabled' => 'boolean', 'active' => 'boolean', 'sort_order' => 'integer', 'manual_overrides' => 'array', 'last_synced_at' => 'datetime'];
    }

    /**
     * Fields the catalog sync writes from the provider feed. Anything an administrator pins
     * is removed from this set for that game, so a manual decision is not undone on the next
     * refresh.
     */
    public const SYNC_MANAGED = [
        'category_id', 'title', 'slug', 'description', 'tag', 'art', 'symbol', 'image_url',
        'external_launch_code', 'playable', 'top_game', 'free_bet_supported',
        'crash', 'content_server_enabled', 'active', 'sort_order',
    ];

    /** Toggles the edit form always states outright, so saving is always an explicit choice. */
    public const EXPLICIT_TOGGLES = [
        'top_game', 'active', 'free_bet_supported', 'crash', 'content_server_enabled',
    ];

    public function isPinned(string $field): bool
    {
        return in_array($field, $this->manual_overrides ?? [], true);
    }

    /** @return array<int, string> */
    public function pinnedFields(): array
    {
        return array_values(array_intersect($this->manual_overrides ?? [], self::SYNC_MANAGED));
    }

    /** Record that these fields were set by hand and must survive the next sync. */
    public function pinFields(array $fields): void
    {
        $pinned = array_values(array_unique(array_merge(
            $this->manual_overrides ?? [],
            array_intersect($fields, self::SYNC_MANAGED)
        )));

        if ($pinned !== ($this->manual_overrides ?? [])) {
            $this->forceFill(['manual_overrides' => $pinned])->save();
        }
    }

    public function releasePins(): void
    {
        $this->forceFill(['manual_overrides' => []])->save();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(GameProvider::class, 'provider_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GameCategory::class, 'category_id');
    }

    public function lobbies(): BelongsToMany
    {
        return $this->belongsToMany(GameLobby::class, 'game_lobby_game', 'game_id', 'lobby_id')->withPivot('sort_order');
    }

    /**
     * A game the site may show and launch: switched on itself, and carried by a
     * provider and a category that are both switched on. Availability is read
     * live off those flags rather than copied onto the game row, so re-enabling
     * a category or provider brings its games back on the next request with no
     * resync and no cache to clear.
     */
    /**
     * A QTech game while we are on QTech's integration host. Playable on their
     * side with test money, but a bet in it is a real debit from OUR wallet —
     * so it is kept away from real players until the production host is set.
     */
    public function isIntegrationOnly(): bool
    {
        return $this->sync_source === 'qtech' && \App\Support\Qtech::heldFromPlayers();
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            // See isIntegrationOnly(). Applied here, in the one scope every
            // player-facing query goes through, so no lobby, count, tile or
            // direct URL can surface an integration-only game — and applied
            // for every viewer alike, which is what keeps the shared lobby
            // cache honest. The fixture player's way in is an explicit bypass
            // on the game page and the launcher, never a relaxation of this.
            ->when(\App\Support\Qtech::heldFromPlayers(), fn (Builder $q) => $q->where('sync_source', '!=', 'qtech'))
            // BigBang is retired. Gated here rather than by switching the rows
            // off, because both catalogue syncs write `active => true` back
            // over any such change within the hour — see App\Support\BigBang.
            ->when(\App\Support\BigBang::retired(), fn (Builder $q) => $q->where('sync_source', '!=', 'bigbang'))
            ->where('active', true)
            ->whereHas('provider', fn (Builder $provider) => $provider->where('active', true))
            ->where(fn (Builder $game) => $game
                ->whereNull('category_id')
                ->orWhereHas('category', fn (Builder $category) => $category->where('active', true)));
    }

    /** Sources whose games open through the aggregator's own launcher, not a local page. */
    public const PROVIDER_SOURCES = ['bigbang', 'qtech'];

    /**
     * Whether Play Now should POST to the provider launch route. One answer for
     * every view, so adding a launcher is a one-line change here rather than a
     * hunt through the lobby, the game page and the admin.
     */
    public function launchesViaProvider(): bool
    {
        return (bool) $this->playable && in_array($this->sync_source, self::PROVIDER_SOURCES, true);
    }

    /** The aggregator's name for the game page eyebrow. */
    public function providerLabel(): string
    {
        return match ($this->sync_source) {
            'qtech' => 'QTech',
            'bigbang' => 'BigBang',
            default => 'Local',
        };
    }

    /** A game from an aggregator we have retired. Reaches nobody, fixture included. */
    public function isFromRetiredProvider(): bool
    {
        return $this->sync_source === 'bigbang' && \App\Support\BigBang::retired();
    }

    public function isAvailable(): bool
    {
        return ! $this->isFromRetiredProvider()
            && ! $this->isIntegrationOnly()
            && $this->isAvailableForTesting();
    }

    /**
     * Whether this viewer may reach the game by its direct URL.
     *
     * The gate every route that resolves a game by slug must apply, including
     * ones that only redirect: a route that 302s for a hidden game and 404s for
     * a missing one is an existence oracle, and answers by status code alone
     * which of the unreleased QTech titles are loaded.
     */
    public function isVisibleTo(?User $viewer): bool
    {
        return $this->isAvailable()
            || ($this->isIntegrationOnly()
                && \App\Support\Qtech::isFixturePlayer($viewer)
                && $this->isAvailableForTesting());
    }

    /** isAvailable() without the integration gate — for the fixture player only. */
    public function isAvailableForTesting(): bool
    {
        return $this->active
            && (!$this->provider_id || (bool) $this->provider?->active)
            && (!$this->category_id || (bool) $this->category?->active);
    }

    public function getImageSourceAttribute(): ?string
    {
        if ($this->image_path) {
            return asset('storage/'.$this->image_path);
        }

        return $this->rightSizedArt($this->image_url);
    }

    /**
     * Ask QTech's image service for an image the size we actually draw.
     *
     * Their default is a full-resolution PNG: measured across the live
     * catalogue, between 220 KB and 530 KB per tile, for a card that renders
     * around 300 pixels wide. The lobby shows a hundred of them. Nobody
     * notices on a desk in an office; on the mobile connections this product
     * is actually used over, it is the single most expensive thing on the
     * page — far more than the markup.
     *
     * The service takes `width` and `format`, which together bring the same
     * image to roughly 12–24 KB, about twenty times smaller, at a resolution
     * that is still sharp on a high-density phone screen. Applied here rather
     * than in the synchroniser so it covers the ten thousand rows already
     * stored, and so changing the size later needs no re-sync.
     */
    private function rightSizedArt(?string $url): ?string
    {
        if (! $url || ! str_contains($url, '/images/')) {
            return $url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $fromQtech = false;
        foreach (\App\Support\Qtech::allowedLaunchHosts() as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                $fromQtech = true;
                break;
            }
        }

        // Only QTech's service is known to take these. Anything else is left
        // exactly as the feed gave it, including a URL that already carries a
        // size someone chose deliberately.
        if (! $fromQtech || str_contains($url, 'width=') || str_contains($url, 'format=')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'width=300&format=webp';
    }

    /**
     * A display-ready version of the title with spaces inserted between
     * run-together CamelCase words and numbers.
     *
     * Feed titles often arrive as a single concatenated string:
     *   "MultiHot5Deluxe" → "Multi Hot 5 Deluxe"
     *   "GatesofOlympus"  → "Gates of Olympus"
     *   "SugarRush1000"   → "Sugar Rush 1000"
     *
     * The original `title` is preserved unchanged (for search, slugs, etc).
     */
    public function getDisplayTitleAttribute(): string
    {
        $title = $this->title;

        // Insert a space before an uppercase letter that follows a lowercase letter or digit.
        $title = (string) preg_replace('/([a-z\d])([A-Z])/', '$1 $2', $title);

        // Insert a space before a digit sequence that follows a letter.
        $title = (string) preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $title);

        // Insert a space before a letter that follows a digit.
        $title = (string) preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $title);

        // Collapse any multi-spaces introduced by the above rules.
        $title = (string) preg_replace('/\s{2,}/', ' ', $title);

        return trim($title);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
