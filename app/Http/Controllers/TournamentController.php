<?php

namespace App\Http\Controllers;

use App\Models\GameRound;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Daily Tournament: a live leaderboard of the day's biggest stakes, resetting at midnight.
 *
 * The board is derived from recorded game rounds, so it can never disagree with the wallet
 * ledger. Prize amounts are the published prize table — they are advertised here and paid
 * by an administrator, nothing is credited automatically from this screen.
 */
class TournamentController
{
    private const DAYS = 4;
    private const BOARD_SIZE = 20;

    public function index(Request $request): View
    {
        $offset = max(0, min(self::DAYS - 1, $request->integer('day')));
        $day = Carbon::today()->subDays($offset);
        $prizes = $this->prizeTable();

        return view('player.tournament', [
            'offset' => $offset,
            'day' => $day,
            'days' => $this->dayTabs(),
            'board' => $this->leaderboard($day, $prizes),
            'prizes' => $prizes,
            'paidPlaces' => (int) PlatformSetting::valueFor('tournament_paid_places', 10),
            'prizePool' => $this->prizePool($prizes),
            'resetsAt' => Carbon::today()->endOfDay(),
            'isToday' => $offset === 0,
            'me' => $request->user(),
        ]);
    }

    /** @return array<int, array{offset: int, label: string, date: Carbon}> */
    private function dayTabs(): array
    {
        return collect(range(0, self::DAYS - 1))
            ->map(fn (int $offset) => [
                'offset' => $offset,
                'label' => match ($offset) {
                    0 => 'Today',
                    1 => 'Yesterday',
                    default => $offset.' days ago',
                },
                'date' => Carbon::today()->subDays($offset),
            ])
            ->all();
    }

    /**
     * Ranked by total stake for the day. Ties break on the earlier first bet, so a player
     * cannot overtake an equal stake simply by playing later.
     *
     * @param  array<int, float>  $prizes
     * @return Collection<int, array<string, mixed>>
     */
    private function leaderboard(Carbon $day, array $prizes): Collection
    {
        $paidPlaces = (int) PlatformSetting::valueFor('tournament_paid_places', 10);

        return GameRound::query()
            ->selectRaw('user_id, COUNT(*) AS rounds, COALESCE(SUM(stake), 0) AS staked, MIN(created_at) AS first_bet_at')
            ->with('user:id,name,first_name,phone')
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->groupBy('user_id')
            ->orderByDesc('staked')
            ->orderBy('first_bet_at')
            ->limit(self::BOARD_SIZE)
            ->get()
            ->values()
            ->map(function (GameRound $row, int $index) use ($prizes, $paidPlaces): array {
                $rank = $index + 1;

                return [
                    'rank' => $rank,
                    'user_id' => (int) $row->user_id,
                    'name' => $this->displayName($row->user),
                    'phone' => $this->maskPhone($row->user?->phone),
                    'rounds' => (int) $row->rounds,
                    'staked' => (float) $row->staked,
                    'prize' => $rank <= $paidPlaces ? ($prizes[$rank] ?? $prizes['other']) : 0.0,
                ];
            });
    }

    /** @return array<int|string, float> */
    private function prizeTable(): array
    {
        return [
            1 => (float) PlatformSetting::valueFor('tournament_prize_first', 500),
            2 => (float) PlatformSetting::valueFor('tournament_prize_second', 250),
            3 => (float) PlatformSetting::valueFor('tournament_prize_third', 100),
            'other' => (float) PlatformSetting::valueFor('tournament_prize_other', 50),
        ];
    }

    /** @param array<int|string, float> $prizes */
    private function prizePool(array $prizes): float
    {
        $paidPlaces = max(0, (int) PlatformSetting::valueFor('tournament_paid_places', 10));
        $runnersUp = max(0, $paidPlaces - 3) * $prizes['other'];

        return $prizes[1] + $prizes[2] + $prizes[3] + $runnersUp;
    }

    private function displayName(?object $user): string
    {
        if (! $user) {
            return 'Player';
        }

        $name = trim((string) ($user->first_name ?: $user->name));

        return $name !== '' ? \Illuminate\Support\Str::limit($name, 18, '') : 'Player';
    }

    /** Leaderboards are public to every player, so only the ends of a number are shown. */
    private function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) < 8) {
            return '';
        }

        return substr($digits, 0, 7).'****'.substr($digits, -2);
    }
}
