<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\AgentOrder;
use App\Models\GameRound;
use App\Models\Promotion;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController
{
    /**
     * How long a set of dashboard figures may be reused.
     *
     * This page answers roughly twenty separate aggregates — counts and sums over
     * rounds, wallet transactions, members, orders and agent float — and every one
     * of them scans a table that only grows. It is also the first page every
     * administrator opens and the one they leave open, so it was re-running the lot
     * on every visit and every refresh.
     *
     * Half a minute is short enough that an operator watching a busy evening still
     * sees movement, and long enough that a room full of open dashboards costs one
     * set of queries rather than one set each. The presence counter is deliberately
     * left outside the cache: "who is online" is the one figure whose whole value
     * is that it is current.
     */
    private const STATS_TTL = 30;

    public function index(Request $request): View
    {
        [$from, $to, $range] = $this->resolvePeriod($request);

        $cacheKey = 'admin.dashboard.'.md5(implode('|', [
            $range,
            $from->toDateString(),
            $to->toDateString(),
        ]));

        [$stats, $deltas, $trend, $recentRounds, $topPlayers] = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::STATS_TTL),
            fn () => $this->buildDashboard($from, $to)
        );

        $stats['onlineUsers'] = \App\Http\Middleware\TrackUserPresence::getOnlineCount();

        // Read-only and cheap: counts over an indexed log, wrapped so a brand
        // that has not applied the security migration still gets its dashboard.
        $security = $this->securityWatch();

        return view('admin.dashboard', [
            'deltas' => $deltas,
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'stats' => $stats,
            'trend' => $trend,
            'recentRounds' => $recentRounds,
            'topPlayers' => $topPlayers,
            'security' => $security,
        ]);
    }

    /**
     * The last day of sign-up and sign-in pressure, from the security log.
     *
     * @return array{ready: bool, registered: int, blocked: int, failed: int, limited: int, level: string}
     */
    private function securityWatch(): array
    {
        $out = ['ready' => false, 'registered' => 0, 'blocked' => 0, 'failed' => 0, 'limited' => 0, 'level' => 'quiet'];

        try {
            $since = now()->subDay();
            $count = fn (string $type, string $outcome) => \App\Models\SecurityEvent::query()
                ->where('type', $type)->where('outcome', $outcome)->where('created_at', '>=', $since)->count();

            $out['registered'] = $count('registration', 'allowed');
            $out['blocked'] = $count('registration', 'blocked');
            $out['failed'] = $count('login', 'failed');
            $out['limited'] = $count('login', 'blocked');
            $out['ready'] = true;
        } catch (\Throwable) {
            return $out;
        }

        $pressure = $out['blocked'] + $out['limited'] + intdiv($out['failed'], 4);
        $out['level'] = $pressure >= 40 ? 'high' : ($pressure >= 10 ? 'watch' : 'quiet');

        return $out;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, ?float>, 2: array, 3: mixed, 4: mixed} */
    private function buildDashboard(Carbon $from, Carbon $to): array
    {
        $rounds = GameRound::query()->whereBetween('created_at', [
            $from->copy()->startOfDay(),
            $to->copy()->endOfDay(),
        ]);

        $totalBets = (clone $rounds)->count();
        $betVolume = (int) (clone $rounds)->sum('stake');
        $payouts = (int) (clone $rounds)->sum('payout');
        $averageBet = $totalBets > 0 ? (int) round($betVolume / $totalBets) : 0;

        $window = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $wallet = fn (string $type) => WalletTransaction::query()
            ->where('type', $type)
            ->where('status', 'success')
            ->whereBetween('created_at', $window);

        $deposits = (float) (clone $wallet('deposit'))->sum(DB::raw('ABS(amount)'));
        $withdrawals = (float) (clone $wallet('withdrawal'))->sum(DB::raw('ABS(amount)'));
        $pendingPayouts = AgentOrder::query()
            ->whereIn('type', ['withdrawal', 'manual_withdrawal'])
            ->whereIn('status', ['pending', 'processing', 'payout_submitted']);

        return [
            [
                'members' => User::query()->where('role', User::ROLE_PLAYER)->count(),
                'bets' => $totalBets,
                'volume' => $betVolume,
                'payouts' => $payouts,
                'ggr' => $betVolume - $payouts,
                'promotions' => Promotion::query()->where('active', true)->count(),
                'average' => $averageBet,
                'playerBalances' => (int) User::query()->where('role', User::ROLE_PLAYER)->sum('credits'),
                'deposits' => $deposits,
                'depositCount' => (clone $wallet('deposit'))->count(),
                'withdrawals' => $withdrawals,
                'withdrawalCount' => (clone $wallet('withdrawal'))->count(),
                'netCash' => $deposits - $withdrawals,
                'newMembers' => User::query()->where('role', User::ROLE_PLAYER)->whereBetween('created_at', $window)->count(),
                'activePlayers' => (clone $rounds)->distinct()->count('user_id'),
                'pendingPayoutCount' => (clone $pendingPayouts)->count(),
                'pendingPayoutTotal' => (float) (clone $pendingPayouts)->sum('amount'),
                'rtp' => $betVolume > 0 ? ($payouts / $betVolume) * 100 : null,
                'agentFloat' => (float) Agent::query()->where('active', true)->sum('balance'),
            ],
            $this->periodDeltas($from, $to, [
                'bets' => $totalBets,
                'volume' => $betVolume,
                'payouts' => $payouts,
                'ggr' => $betVolume - $payouts,
                'deposits' => $deposits,
            ]),
            $this->buildTrend($to),
            (clone $rounds)->with('user')->latest()->limit(6)->get(),
            User::query()->where('role', User::ROLE_PLAYER)->orderByDesc('credits')->limit(5)->get(),
        ];
    }



    /**
     * Movement against the previous period of the same length.
     *
     * A figure on its own says nothing about direction — 9,842 bets is only
     * meaningful next to what the same span produced last time. The comparison
     * window is the equal-length stretch ending the instant this one begins,
     * so "last 7 days" is measured against the 7 days before it.
     *
     * Returns null for a metric whose baseline was zero: "up from nothing" is
     * not a percentage, and rendering one would invent precision.
     */
    private function periodDeltas(Carbon $from, Carbon $to, array $current): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $length = $start->diffInSeconds($end);

        $prevEnd = $start->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subSeconds($length);
        $prevWindow = [$prevStart, $prevEnd];

        $prevRounds = GameRound::query()->whereBetween('created_at', $prevWindow);
        $prevBets = (clone $prevRounds)->count();
        $prevVolume = (int) (clone $prevRounds)->sum('stake');
        $prevPayouts = (int) (clone $prevRounds)->sum('payout');
        $prevDeposits = (float) WalletTransaction::query()
            ->where('type', 'deposit')->where('status', 'success')
            ->whereBetween('created_at', $prevWindow)
            ->sum(DB::raw('ABS(amount)'));

        $previous = [
            'bets' => $prevBets,
            'volume' => $prevVolume,
            'payouts' => $prevPayouts,
            'ggr' => $prevVolume - $prevPayouts,
            'deposits' => $prevDeposits,
        ];

        $deltas = [];
        foreach ($current as $key => $now) {
            $before = $previous[$key] ?? 0;
            $deltas[$key] = $before > 0
                ? round((((float) $now - (float) $before) / (float) $before) * 100, 1)
                : null;
        }

        return $deltas;
    }

    /**
     * Daily series for the dashboard chart and metric sparklines.
     *
     * The headline figures follow whatever period the operator picked, but a
     * trend needs more than one point — "Today" would otherwise draw a single
     * dot. So the series always covers the trailing TREND_DAYS ending at $to,
     * giving every range the same amount of context.
     */
    private const TREND_DAYS = 14;

    private function buildTrend(Carbon $to): array
    {
        $end = $to->copy()->endOfDay();
        $start = $to->copy()->subDays(self::TREND_DAYS - 1)->startOfDay();

        // Pre-seed every day so gaps render as zero instead of collapsing the axis.
        $days = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $days[$cursor->format('Y-m-d')] = [
                'label' => $cursor->format('M j'),
                'volume' => 0.0,
                'payout' => 0.0,
                'ggr' => 0.0,
                'bets' => 0,
                'deposits' => 0.0,
                'withdrawals' => 0.0,
                'members' => 0,
            ];
        }

        $dateKey = "DATE(created_at)";

        GameRound::query()
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw($dateKey))
            ->get([
                DB::raw("{$dateKey} as day"),
                DB::raw('COUNT(*) as bets'),
                DB::raw('SUM(stake) as volume'),
                DB::raw('SUM(payout) as payout'),
            ])
            ->each(function ($row) use (&$days) {
                $key = Carbon::parse($row->day)->format('Y-m-d');
                if (! isset($days[$key])) {
                    return;
                }
                $days[$key]['bets'] = (int) $row->bets;
                $days[$key]['volume'] = (float) $row->volume;
                $days[$key]['payout'] = (float) $row->payout;
                $days[$key]['ggr'] = (float) $row->volume - (float) $row->payout;
            });

        WalletTransaction::query()
            ->whereIn('type', ['deposit', 'withdrawal'])
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw($dateKey), 'type')
            ->get([
                DB::raw("{$dateKey} as day"),
                'type',
                DB::raw('SUM(ABS(amount)) as total'),
            ])
            ->each(function ($row) use (&$days) {
                $key = Carbon::parse($row->day)->format('Y-m-d');
                if (! isset($days[$key])) {
                    return;
                }
                $days[$key][$row->type === 'deposit' ? 'deposits' : 'withdrawals'] = (float) $row->total;
            });

        User::query()
            ->where('role', User::ROLE_PLAYER)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw($dateKey))
            ->get([DB::raw("{$dateKey} as day"), DB::raw('COUNT(*) as total')])
            ->each(function ($row) use (&$days) {
                $key = Carbon::parse($row->day)->format('Y-m-d');
                if (isset($days[$key])) {
                    $days[$key]['members'] = (int) $row->total;
                }
            });

        $rows = array_values($days);

        return [
            'days' => self::TREND_DAYS,
            'from' => $start,
            'to' => $end,
            'labels' => array_column($rows, 'label'),
            'volume' => array_column($rows, 'volume'),
            'payout' => array_column($rows, 'payout'),
            'ggr' => array_column($rows, 'ggr'),
            'bets' => array_column($rows, 'bets'),
            'deposits' => array_column($rows, 'deposits'),
            'withdrawals' => array_column($rows, 'withdrawals'),
            'members' => array_column($rows, 'members'),
        ];
    }

    public function placeholder(string $module): View
    {
        $title = Str::of($module)->replace('-', ' ')->title();

        return view('admin.placeholder', compact('title'));
    }

    private function resolvePeriod(Request $request): array
    {
        $range = $request->string('range')->toString() ?: 'today';
        $to = $request->date('to') ?: now();

        $from = match ($range) {
            '7days' => $to->copy()->subDays(6),
            'month' => $to->copy()->startOfMonth(),
            'year' => $to->copy()->startOfYear(),
            'custom' => $request->date('from') ?: $to->copy(),
            default => $to->copy(),
        };

        return [Carbon::parse($from), Carbon::parse($to), $range];
    }
}
