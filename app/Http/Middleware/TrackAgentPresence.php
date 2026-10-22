<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records which agents are at their desk.
 *
 * TrackUserPresence deliberately ignores anyone who is not a User, because the agent
 * portal authenticates an Agent and agents carry none of the fields that tracker
 * records. So nothing knew whether an agent was working — which matters to a player
 * choosing who to send a deposit to, since an agent who is offline will not approve
 * it until they come back.
 *
 * Same shape as the player tracker and for the same reasons: one cache entry holding
 * a map of agent id to last sighting, pruned on write, no per-agent lookups on read.
 * It stores only the id and the timestamp — the picker joins the rest from the query
 * it was already running, so nothing about an agent is duplicated into the cache.
 *
 * Two requests landing together can overwrite one another's heartbeat. For a presence
 * display that is fine: the loser is recorded again on their next request.
 */
class TrackAgentPresence
{
    /** How long a sighting counts as "online" with no further requests. */
    public const TTL_MINUTES = 5;

    /** How old a sighting may grow before it is worth re-recording. */
    private const REFRESH_SECONDS = 60;

    /** Hard cap so a busy shift cannot grow the entry without bound. */
    private const MAX_TRACKED = 500;

    private const KEY = 'presence:agents';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $agent = Auth::guard('agent')->user();

        if (! $agent instanceof Agent) {
            return $response;
        }

        $map = self::freshEntries();

        // Same write-skipping as the player tracker, same reason: one shared cache
        // entry behind one lock. A sighting under a minute old says all it needs to.
        $existing = $map[(string) $agent->getKey()] ?? null;
        if (is_string($existing)
            && strcmp($existing, now()->subSeconds(self::REFRESH_SECONDS)->toIso8601String()) >= 0) {
            return $response;
        }

        $map[(string) $agent->getKey()] = now()->toIso8601String();

        if (count($map) > self::MAX_TRACKED) {
            arsort($map);
            $map = array_slice($map, 0, self::MAX_TRACKED, true);
        }

        Cache::put(self::KEY, $map, now()->addMinutes(self::TTL_MINUTES * 2));

        return $response;
    }

    /**
     * Agent ids seen within the window, as a lookup.
     *
     * @return array<int, true>
     */
    public static function onlineAgentIds(): array
    {
        $ids = [];

        foreach (array_keys(self::freshEntries()) as $id) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }

    public static function isOnline(int|string $agentId): bool
    {
        return array_key_exists((string) $agentId, self::freshEntries());
    }

    public static function onlineCount(): int
    {
        return count(self::freshEntries());
    }

    /**
     * The stored map minus anything past its time to live.
     *
     * @return array<string, string>
     */
    private static function freshEntries(): array
    {
        $map = Cache::get(self::KEY, []);

        if (! is_array($map)) {
            return [];
        }

        // toIso8601String() output orders lexicographically, so this is one strcmp
        // per entry rather than a Carbon parse per entry per request. Leans on
        // APP_TIMEZONE being fixed-offset (no DST); revisit before changing zones.
        $cutoff = now()->subMinutes(self::TTL_MINUTES)->toIso8601String();

        return array_filter($map, fn ($seenAt): bool => is_string($seenAt) && strcmp($seenAt, $cutoff) >= 0);
    }
}
