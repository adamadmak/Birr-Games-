<?php

namespace App\Http\Middleware;

use App\Models\Game;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records what each signed-in player is doing, for the live views in the admin.
 *
 * This runs on every authenticated request, so it has to be cheap. The whole picture lives
 * in one cache entry: a map of user id to their last sighting, pruned and size-capped on
 * write. The earlier design kept a registry of every id ever seen plus one entry per user,
 * which meant a read, an append and a write of an ever-growing array on every request, and
 * one cache read per user when the admin opened the page. On the default file cache that
 * grew into seconds of disk work per request and never shrank.
 *
 * Two requests landing together can overwrite one another's heartbeat. For a presence
 * display that is acceptable: the loser is simply recorded on their next request, a few
 * seconds later. It is not worth a lock on every page view.
 */
class TrackUserPresence
{
    /** How long a sighting counts as "online" with no further requests. */
    private const TTL_MINUTES = 5;

    /** How old a sighting may grow before an unchanged page is worth re-recording. */
    private const REFRESH_SECONDS = 60;

    /** Hard cap so a traffic spike cannot grow the entry without bound. */
    private const MAX_TRACKED = 400;

    private const KEY = 'presence:map';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Auth::user() follows whichever guard authenticated the request, and the agent
        // portal authenticates an Agent, not a User. Agents carry none of the fields this
        // tracker records, so touching one here crashed every agent portal page.
        $user = Auth::user();

        if (! $user instanceof User) {
            return $response;
        }

        [$currentPage, $currentGame, $gameTitle] = $this->describe($request);

        $map = $this->freshEntries();

        /*
         * The balance poll is a heartbeat, not a place the player went. Left alone
         * it did two wrong things at once: it overwrote "Playing Nile Rush" with
         * the generic player-area label every few seconds, and by alternating the
         * label it defeated the unchanged-page write-skip below for exactly the
         * players who poll the most. A poll keeps whatever the entry already says.
         */
        $existingEntry = $map[(string) $user->id] ?? null;
        if ($request->route()?->getName() === 'player.balance' && is_array($existingEntry)) {
            $currentPage = (string) ($existingEntry['current_page'] ?? $currentPage);
            $currentGame = $existingEntry['current_game'] ?? null;
            $gameTitle = $existingEntry['current_game_title'] ?? null;
        }

        /*
         * Skip the write when it would say nothing new. The map is one cache entry
         * behind one write lock, and at the 400-player cap it serialises to ~110 KB —
         * so every authenticated request rewriting it made that lock the queue the
         * whole site stood in under load. The balance poll alone rewrote it every few
         * seconds per open sportsbook tab.
         *
         * A sighting only needs refreshing before it ages out of the 5-minute window,
         * so an unchanged page label within a minute is a no-op. A changed label still
         * writes immediately: "Playing X" transitions stay live for the operator.
         */
        $existing = $existingEntry;
        if (is_array($existing)
            && ($existing['current_page'] ?? null) === $currentPage
            && strcmp((string) ($existing['last_active_at'] ?? ''), now()->subSeconds(self::REFRESH_SECONDS)->toIso8601String()) >= 0) {
            return $response;
        }

        $map[(string) $user->id] = [
            'id' => $user->id,
            'name' => $user->name,
            'handle' => $user->handle(),
            'role' => $user->role,
            'current_page' => $currentPage,
            'current_game' => $currentGame,
            'current_game_title' => $gameTitle,
            'last_active_at' => now()->toIso8601String(),
        ];

        if (count($map) > self::MAX_TRACKED) {
            // Keep the most recent sightings; the rest would have aged out shortly anyway.
            $map = collect($map)
                ->sortByDesc('last_active_at')
                ->take(self::MAX_TRACKED)
                ->all();
        }

        Cache::put(self::KEY, $map, now()->addMinutes(self::TTL_MINUTES * 2));

        return $response;
    }

    /** @return Collection<int, array<string, mixed>> most recently seen first */
    public static function getOnlineUsers(): Collection
    {
        return collect((new self)->freshEntries())
            ->sortByDesc('last_active_at')
            ->values();
    }

    public static function getOnlineCount(): int
    {
        return count((new self)->freshEntries());
    }

    /**
     * The stored map minus anything past its time to live. One cache read, no per-user
     * lookups.
     *
     * @return array<string, array<string, mixed>>
     */
    private function freshEntries(): array
    {
        $map = Cache::get(self::KEY, []);

        if (! is_array($map)) {
            return [];
        }

        // Every entry was written by this class via toIso8601String(), a fixed-width
        // single-offset format that orders lexicographically — so the prune is one
        // strcmp per entry instead of one Carbon parse per entry per request, which
        // at the 400-entry cap was most of this middleware's cost.
        //
        // This leans on APP_TIMEZONE being a fixed-offset zone (Africa/Addis_Ababa
        // has no DST). If the app ever moves to a zone whose offset shifts, entries
        // written across the shift mis-compare for up to one cache lifetime; revisit
        // the comparison before changing the timezone.
        $cutoff = now()->subMinutes(self::TTL_MINUTES)->toIso8601String();

        return array_filter($map, fn ($entry): bool => is_array($entry)
            && strcmp((string) ($entry['last_active_at'] ?? ''), $cutoff) >= 0);
    }

    /**
     * What the player is looking at, in words an operator understands.
     *
     * @return array{0: string, 1: string|null, 2: string|null} page label, game slug, game title
     */
    private function describe(Request $request): array
    {
        $route = $request->route();
        $routeName = $route ? $route->getName() : null;

        if (in_array($routeName, ['games.show', 'games.play', 'games.provider.launch'], true)) {
            $gameParam = $request->route('game') ?? $request->route('slug');

            if ($gameParam instanceof Game) {
                $slug = (string) $gameParam->slug;
                $title = filled($gameParam->title) ? $gameParam->title : $slug;

                return ['Playing '.$title, $slug, $title];
            }

            if (is_string($gameParam) || is_numeric($gameParam)) {
                $slug = (string) $gameParam;
                // One keyed lookup, cached briefly: this route is hit repeatedly while playing.
                $title = Cache::remember(
                    'presence:game-title:'.$slug,
                    now()->addMinutes(10),
                    fn () => Game::query()->where('slug', $slug)->value('title') ?: $slug
                );

                return ['Playing '.$title, $slug, $title];
            }
        }

        return [match (true) {
            $routeName === null, $routeName === 'home' => 'Browsing Lobby',
            str_starts_with((string) $routeName, 'player.wallet') => 'Viewing Wallet',
            str_starts_with((string) $routeName, 'player.tournament') => 'Daily Tournament',
            str_starts_with((string) $routeName, 'player.profile') => 'Viewing Profile',
            str_starts_with((string) $routeName, 'player.') => 'Player Area',
            str_starts_with((string) $routeName, 'admin.') => 'Admin Panel',
            str_starts_with((string) $routeName, 'agent.') => 'Agent Portal',
            default => 'Browsing',
        }, null, null];
    }
}
