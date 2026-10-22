<?php

namespace App\Http\Controllers\Admin;

use App\Http\Middleware\TrackUserPresence;
use App\Models\BigBangSession;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Who is on the system right now, and what they are playing.
 *
 * "Online" means the player holds an open provider session (status `active`) that has
 * shown activity inside the idle window. Provider sessions carry `last_activity_at`,
 * which the wallet callback stamps on every bet and win, so this reflects real play
 * rather than a browser tab someone left open.
 *
 * In-house games do not open a provider session, so recent rounds from the local game
 * engine are folded in as well; otherwise a player spinning Lucky Dice would look offline.
 */
class OnlinePlayersController
{
    private const IDLE_MINUTES = 15;

    public function index(Request $request): View
    {
        $window = max(1, min(180, $request->integer('window') ?: self::IDLE_MINUTES));
        $since = now()->subMinutes($window);

        $players = $this->presencePlayers($since)
            ->concat($this->providerPlayers($since))
            ->concat($this->inHousePlayers($since))
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $this->mergeSources($rows))
            ->sortByDesc('last_seen_at')
            ->values();

        return view('admin.online-players', [
            'players' => $players,
            'window' => $window,
            'totalOnline' => $players->count(),
            'playing' => $players->where('is_playing', true)->count(),
            'idle' => $players->where('is_playing', false)->count(),
            'staked' => $players->sum('staked'),
            'generatedAt' => now(),
        ]);
    }

    /**
     * One person may appear from several sources at once: a heartbeat from the page they
     * have open, a provider session, and rounds they just bet. Fold them into a single row,
     * keeping the most specific game and the most recent sighting.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function mergeSources(Collection $rows): array
    {
        $newest = $rows->sortByDesc('last_seen_at')->first();
        // A concrete game beats a "Browsing" heartbeat.
        $withGame = $rows->where('has_game', true)->sortByDesc('last_seen_at')->first();
        $bets = $rows->firstWhere('rounds', '>', 0);

        return [
            'user_id' => $newest['user_id'],
            'name' => $newest['name'],
            'phone' => $newest['phone'],
            'credits' => $newest['credits'],
            'game' => $withGame['game'] ?? $newest['game'],
            'provider' => $withGame['provider'] ?? $newest['provider'],
            'channel' => $withGame['channel'] ?? $newest['channel'],
            'started_at' => $rows->pluck('started_at')->filter()->min(),
            'last_seen_at' => $newest['last_seen_at'],
            'is_playing' => $rows->contains('is_playing', true),
            'rounds' => (int) ($bets['rounds'] ?? 0),
            'staked' => (float) ($bets['staked'] ?? 0),
            'has_game' => $withGame !== null,
        ];
    }

    /**
     * Everyone with a live heartbeat, written by the presence middleware on every
     * authenticated page view. This is the only source that sees a player who is signed in
     * and browsing without having opened a game yet.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function presencePlayers(Carbon $since): Collection
    {
        $entries = TrackUserPresence::getOnlineUsers()
            ->filter(fn ($entry) => is_array($entry)
                && ($entry['role'] ?? null) === User::ROLE_PLAYER
                && filled($entry['last_active_at'] ?? null)
                && Carbon::parse($entry['last_active_at'])->greaterThanOrEqualTo($since));

        if ($entries->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $entries->pluck('id'))
            ->get(['id', 'name', 'first_name', 'phone', 'credits'])
            ->keyBy('id');

        return $entries->map(function (array $entry) use ($users): array {
            $user = $users->get($entry['id']);
            $lastSeen = Carbon::parse($entry['last_active_at']);
            $game = $entry['current_game_title'] ?? $entry['current_game'] ?? null;

            return [
                'user_id' => (int) $entry['id'],
                'name' => $this->displayName($user) !== 'Player' ? $this->displayName($user) : ($entry['name'] ?: 'Player'),
                'phone' => $user?->phone,
                'credits' => (float) ($user?->credits ?? 0),
                'game' => $game ?: ($entry['current_page'] ?? 'Browsing'),
                'provider' => $game ? 'Lucy game' : '—',
                'channel' => $entry['current_page'] ?? 'Signed in',
                'started_at' => $lastSeen,
                'last_seen_at' => $lastSeen,
                'is_playing' => $game !== null,
                'rounds' => 0,
                'staked' => 0.0,
                'has_game' => $game !== null,
            ];
        })->values();
    }

    /**
     * Players inside a live BigBang session. The provider tells us which game the session
     * was opened for, so this is the one place the actual game title is known.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function providerPlayers(Carbon $since): Collection
    {
        return BigBangSession::query()
            ->with(['user:id,name,first_name,phone,credits', 'game:id,title,provider_id', 'game.provider:id,name'])
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->where('last_activity_at', '>=', $since)
                ->orWhere('created_at', '>=', $since))
            ->orderByDesc('last_activity_at')
            ->get()
            ->map(function (BigBangSession $session) use ($since): array {
                $lastSeen = $session->last_activity_at ?: $session->created_at;

                return [
                    'user_id' => (int) $session->user_id,
                    'name' => $this->displayName($session->user),
                    'phone' => $session->user?->phone,
                    'credits' => (float) ($session->user?->credits ?? 0),
                    'game' => $session->game?->title ?: 'Provider game',
                    'provider' => $session->game?->provider?->name ?: 'BigBang',
                    'channel' => $session->mode === 'demo' ? 'Demo session' : 'Live session',
                    'started_at' => $session->created_at,
                    'last_seen_at' => $lastSeen,
                    // A stamped last_activity_at means the wallet callback fired: real money moved.
                    'is_playing' => $session->last_activity_at !== null && $session->last_activity_at->greaterThanOrEqualTo($since),
                    'rounds' => 0,
                    'staked' => 0.0,
                    'has_game' => true,
                ];
            });
    }

    /**
     * Players on the in-house game engine, which records a round per bet instead of holding
     * a session. Their stake total for the window is real money wagered.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function inHousePlayers(Carbon $since): Collection
    {
        return GameRound::query()
            ->selectRaw('user_id, game, COUNT(*) AS rounds, COALESCE(SUM(stake), 0) AS staked, MIN(created_at) AS started_at, MAX(created_at) AS last_seen_at')
            ->with('user:id,name,first_name,phone,credits')
            ->where('created_at', '>=', $since)
            ->groupBy('user_id', 'game')
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (GameRound $row): array => [
                'user_id' => (int) $row->user_id,
                'name' => $this->displayName($row->user),
                'phone' => $row->user?->phone,
                'credits' => (float) ($row->user?->credits ?? 0),
                'game' => $this->gameTitle((string) $row->game),
                'provider' => 'Lucy in-house',
                'channel' => 'Game engine',
                'started_at' => Carbon::parse($row->started_at),
                'last_seen_at' => Carbon::parse($row->last_seen_at),
                'is_playing' => true,
                'rounds' => (int) $row->rounds,
                'staked' => (float) $row->staked,
                'has_game' => true,
            ]);
    }

    private function displayName(?object $user): string
    {
        if (! $user) {
            return 'Player';
        }

        $name = trim((string) ($user->name ?: $user->first_name));

        return $name !== '' ? $name : 'Player';
    }

    /** game_rounds stores a slug, so turn `lucky-dice` into `Lucky Dice`. */
    private function gameTitle(string $slug): string
    {
        return $slug === '' ? 'In-house game' : str($slug)->replace(['-', '_'], ' ')->title()->toString();
    }
}
