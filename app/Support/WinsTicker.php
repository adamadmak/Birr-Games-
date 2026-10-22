<?php

namespace App\Support;

use App\Models\Game;
use App\Models\GameRound;
use Illuminate\Support\Facades\Cache;

/**
 * The "live wins" strip on the lobby.
 *
 * Every entry is a real round: a stake that paid out more than it cost, read
 * from game_rounds. Nothing is invented and nothing is padded — with no recent
 * wins the strip simply does not render. Players are masked to their first two
 * characters, because a public page must not name the people who won.
 *
 * The same list is shown to every visitor, so it is built once a minute and
 * shared rather than queried per page view on the busiest page of the site.
 */
final class WinsTicker
{
    private const TTL_SECONDS = 60;

    /** How far back a win still counts as "recent". */
    private const WINDOW_HOURS = 72;

    /**
     * @return list<array{who: string, game: string, amount: float}>
     */
    public static function recent(int $limit = 12): array
    {
        try {
            return Cache::remember('lobby.recent-wins.'.$limit, self::TTL_SECONDS, fn () => self::build($limit));
        } catch (\Throwable) {
            // A ticker is decoration: any failure reads as "nothing to show".
            return [];
        }
    }

    /** @return list<array{who: string, game: string, amount: float}> */
    private static function build(int $limit): array
    {
        $rounds = GameRound::query()
            ->with('user:id,username,first_name,name')
            ->whereColumn('payout', '>', 'stake')
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($rounds->isEmpty()) {
            return [];
        }

        $titles = Game::query()->whereIn('slug', $rounds->pluck('game')->unique()->all())->pluck('title', 'slug');

        $wins = [];

        foreach ($rounds as $round) {
            if (! $round->user) {
                continue;
            }

            $wins[] = [
                'who' => self::mask($round->user->username ?: $round->user->first_name ?: $round->user->name),
                'game' => (string) ($titles[$round->game] ?? str($round->game)->replace(['-', '_'], ' ')->title()),
                'amount' => (float) $round->payout,
            ];
        }

        return $wins;
    }

    /** "Abebe" → "Ab••••". Two visible characters are enough to feel human and too few to identify anyone. */
    public static function mask(?string $name): string
    {
        $name = ltrim(trim((string) $name), '@');

        if ($name === '') {
            return 'Player••••';
        }

        return mb_substr($name, 0, 2).'••••';
    }
}
