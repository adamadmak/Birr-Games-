<?php

namespace App\Http\Controllers\Admin;

use App\Models\BonusClaim;
use App\Models\GameRound;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Player accountability: what every member put in, took out, staked, and won or
 * lost in one auditable grid. Every figure comes from the wallet ledger and the
 * recorded game rounds, never from a cached counter.
 */
class PlayerReportController
{
    /** Sortable columns mapped to the SQL they order by. */
    private const SORTS = [
        'name' => 'users.name',
        'phone' => 'users.phone',
        'deposit_total' => 'deposit_total',
        'withdrawal_total' => 'withdrawal_total',
        'net_deposit' => '(deposit_total - withdrawal_total)',
        'bet_count' => 'bet_count',
        'stake_total' => 'stake_total',
        'payout_total' => 'payout_total',
        'player_net' => '(payout_total - stake_total)',
        'house_ggr' => '(stake_total - payout_total)',
        'bonus_total' => 'bonus_total',
        'balance' => 'users.credits',
        'last_bet_at' => 'last_bet_at',
        'joined' => 'users.created_at',
    ];

    private const SEGMENTS = [
        '' => 'All members',
        'bettors' => 'Placed a bet',
        'depositors' => 'Deposited',
        'withdrawers' => 'Withdrew',
        'winners' => 'Net winners',
        'losers' => 'Net losers',
        'dormant' => 'No activity',
        'archived' => 'Archived accounts',
    ];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $players = $this->playersQuery($filters)
            ->orderByRaw(self::SORTS[$filters['sort']].' '.$filters['direction'])
            ->orderBy('users.id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        return view('admin.players.report', [
            'filters' => $filters,
            'segments' => self::SEGMENTS,
            'players' => $players,
            'summary' => $this->summary($filters),
            'trend' => $this->trend($filters),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'player-accountability-'.$filters['from']->format('Ymd').'-'.$filters['to']->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($filters): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, [
                'Member', 'Phone', 'Username', 'Joined', 'Deposits ETB', 'Deposit count',
                'Withdrawals ETB', 'Withdrawal count', 'Net deposits ETB', 'Bonus ETB',
                'Bets', 'Staked ETB', 'Won ETB', 'Player win/loss ETB', 'House GGR ETB',
                'RTP %', 'Wallet balance ETB', 'Withdrawable ETB', 'Last bet', 'Status',
            ]);

            $this->playersQuery($filters)
                ->orderBy('users.id')
                ->chunk(500, function ($players) use ($output): void {
                    foreach ($players as $player) {
                        $metrics = $this->metrics($player);
                        fputcsv($output, [
                            $player->name, $player->phone, $player->username,
                            $player->created_at?->toDateString(),
                            $metrics['deposit_total'], $metrics['deposit_count'],
                            $metrics['withdrawal_total'], $metrics['withdrawal_count'],
                            $metrics['net_deposit'], $metrics['bonus_total'],
                            $metrics['bet_count'], $metrics['stake_total'], $metrics['payout_total'],
                            $metrics['player_net'], $metrics['house_ggr'],
                            $metrics['rtp'] === null ? '' : round($metrics['rtp'], 2),
                            $player->credits, $player->withdrawable_balance,
                            $metrics['last_bet_at']?->toDateTimeString(),
                            $player->active ? 'Active' : 'Inactive',
                        ]);
                    }
                }, 'users.id');

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Normalised per-player figures used by both the table and the export. */
    public static function metrics(User $player): array
    {
        $stake = (float) ($player->stake_total ?? 0);
        $payout = (float) ($player->payout_total ?? 0);
        $deposits = (float) ($player->deposit_total ?? 0);
        $withdrawals = (float) ($player->withdrawal_total ?? 0);

        return [
            'deposit_total' => $deposits,
            'deposit_count' => (int) ($player->deposit_count ?? 0),
            'withdrawal_total' => $withdrawals,
            'withdrawal_count' => (int) ($player->withdrawal_count ?? 0),
            'net_deposit' => $deposits - $withdrawals,
            'bonus_total' => (float) ($player->bonus_total ?? 0),
            'bet_count' => (int) ($player->bet_count ?? 0),
            'stake_total' => $stake,
            'payout_total' => $payout,
            'player_net' => $payout - $stake,
            'house_ggr' => $stake - $payout,
            'rtp' => $stake > 0 ? ($payout / $stake) * 100 : null,
            'last_bet_at' => $player->last_bet_at ? CarbonImmutable::parse($player->last_bet_at) : null,
        ];
    }

    /** @return Builder<User> */
    private function playersQuery(array $filters): Builder
    {
        $from = $filters['from'];
        $to = $filters['to'];

        $wallet = static function (string $type, string $expression) use ($from, $to) {
            return WalletTransaction::query()
                ->selectRaw($expression)
                ->whereColumn('wallet_transactions.user_id', 'users.id')
                ->where('wallet_transactions.type', $type)
                ->where('wallet_transactions.status', 'success')
                ->whereBetween('wallet_transactions.created_at', [$from, $to]);
        };

        $rounds = static function (string $expression) use ($from, $to) {
            return GameRound::query()
                ->selectRaw($expression)
                ->whereColumn('game_rounds.user_id', 'users.id')
                ->whereBetween('game_rounds.created_at', [$from, $to]);
        };

        $query = User::query()
            ->where('users.role', User::ROLE_PLAYER)
            ->select('users.*')
            ->addSelect([
                'deposit_total' => $wallet('deposit', 'COALESCE(SUM(ABS(amount)), 0)'),
                'deposit_count' => $wallet('deposit', 'COUNT(*)'),
                'withdrawal_total' => $wallet('withdrawal', 'COALESCE(SUM(ABS(amount)), 0)'),
                'withdrawal_count' => $wallet('withdrawal', 'COUNT(*)'),
                'bonus_total' => BonusClaim::query()
                    ->selectRaw('COALESCE(SUM(credits), 0)')
                    ->whereColumn('bonus_claims.user_id', 'users.id')
                    ->whereBetween('bonus_claims.created_at', [$from, $to]),
                'bet_count' => $rounds('COUNT(*)'),
                'stake_total' => $rounds('COALESCE(SUM(stake), 0)'),
                'payout_total' => $rounds('COALESCE(SUM(payout), 0)'),
                'last_bet_at' => $rounds('MAX(game_rounds.created_at)'),
            ])
            ->when($filters['search'], fn (Builder $query, string $term) => $query->where(fn (Builder $nested) => $nested
                ->where('users.name', 'like', "%{$term}%")
                ->orWhere('users.first_name', 'like', "%{$term}%")
                ->orWhere('users.last_name', 'like', "%{$term}%")
                ->orWhere('users.phone', 'like', "%{$term}%")
                ->orWhere('users.username', 'like', '%'.ltrim($term, '@').'%')
                ->when(PhoneNumber::candidates($term) !== [], fn (Builder $phones) => $phones->orWhereIn('users.phone', PhoneNumber::candidates($term)))))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('users.active', $filters['status'] === 'active'));

        // Segments filter rows, so they are expressed as EXISTS/scalar comparisons in the
        // WHERE clause rather than HAVING, which would otherwise collapse the result set.
        $hasRounds = fn (Builder $query) => $query->whereHas('rounds', fn (Builder $rounds) => $rounds->whereBetween('game_rounds.created_at', [$from, $to]));
        $hasWallet = fn (Builder $query, string $type) => $query->whereHas(
            'walletTransactions',
            fn (Builder $wallet) => $wallet->where('type', $type)->where('status', 'success')->whereBetween('wallet_transactions.created_at', [$from, $to])
        );
        $roundNet = '(SELECT COALESCE(SUM(payout - stake), 0) FROM game_rounds'
            .' WHERE game_rounds.user_id = users.id AND game_rounds.created_at BETWEEN ? AND ?)';

        return match ($filters['segment']) {
            // Archived members keep their money trail, so it stays reportable.
            'archived' => $query->onlyTrashed(),
            'bettors' => $hasRounds($query),
            'depositors' => $hasWallet($query, 'deposit'),
            'withdrawers' => $hasWallet($query, 'withdrawal'),
            'winners' => $hasRounds($query)->whereRaw($roundNet.' > 0', [$from, $to]),
            'losers' => $hasRounds($query)->whereRaw($roundNet.' < 0', [$from, $to]),
            'dormant' => $query
                ->whereDoesntHave('rounds', fn (Builder $rounds) => $rounds->whereBetween('game_rounds.created_at', [$from, $to]))
                ->whereDoesntHave('walletTransactions', fn (Builder $wallet) => $wallet
                    ->whereIn('type', ['deposit', 'withdrawal'])
                    ->where('status', 'success')
                    ->whereBetween('wallet_transactions.created_at', [$from, $to])),
            default => $query,
        };
    }

    /** Period totals for exactly the rows the filters produced. */
    private function summary(array $filters): array
    {
        $totals = DB::query()
            ->fromSub($this->playersQuery($filters)->toBase(), 'report')
            ->selectRaw('COUNT(*) AS players')
            ->selectRaw('COALESCE(SUM(deposit_total), 0) AS deposit_total')
            ->selectRaw('COALESCE(SUM(deposit_count), 0) AS deposit_count')
            ->selectRaw('COALESCE(SUM(withdrawal_total), 0) AS withdrawal_total')
            ->selectRaw('COALESCE(SUM(withdrawal_count), 0) AS withdrawal_count')
            ->selectRaw('COALESCE(SUM(bonus_total), 0) AS bonus_total')
            ->selectRaw('COALESCE(SUM(bet_count), 0) AS bet_count')
            ->selectRaw('COALESCE(SUM(stake_total), 0) AS stake_total')
            ->selectRaw('COALESCE(SUM(payout_total), 0) AS payout_total')
            ->selectRaw('COALESCE(SUM(credits), 0) AS balance_total')
            ->selectRaw('COALESCE(SUM(withdrawable_balance), 0) AS withdrawable_total')
            ->selectRaw('SUM(CASE WHEN bet_count > 0 THEN 1 ELSE 0 END) AS active_players')
            ->selectRaw('SUM(CASE WHEN bet_count > 0 AND payout_total > stake_total THEN 1 ELSE 0 END) AS winners')
            ->selectRaw('SUM(CASE WHEN bet_count > 0 AND stake_total > payout_total THEN 1 ELSE 0 END) AS losers')
            ->first();

        $stake = (float) ($totals->stake_total ?? 0);
        $payout = (float) ($totals->payout_total ?? 0);
        $deposits = (float) ($totals->deposit_total ?? 0);
        $withdrawals = (float) ($totals->withdrawal_total ?? 0);

        return [
            'players' => (int) ($totals->players ?? 0),
            'active_players' => (int) ($totals->active_players ?? 0),
            'winners' => (int) ($totals->winners ?? 0),
            'losers' => (int) ($totals->losers ?? 0),
            'deposit_total' => $deposits,
            'deposit_count' => (int) ($totals->deposit_count ?? 0),
            'withdrawal_total' => $withdrawals,
            'withdrawal_count' => (int) ($totals->withdrawal_count ?? 0),
            'net_deposit' => $deposits - $withdrawals,
            'bonus_total' => (float) ($totals->bonus_total ?? 0),
            'bet_count' => (int) ($totals->bet_count ?? 0),
            'stake_total' => $stake,
            'payout_total' => $payout,
            'house_ggr' => $stake - $payout,
            'player_net' => $payout - $stake,
            'rtp' => $stake > 0 ? ($payout / $stake) * 100 : null,
            'hold' => $stake > 0 ? (($stake - $payout) / $stake) * 100 : null,
            'balance_total' => (float) ($totals->balance_total ?? 0),
            'withdrawable_total' => (float) ($totals->withdrawable_total ?? 0),
        ];
    }

    /**
     * Day-by-day cash in, cash out and gross gaming revenue for the sparkline chart.
     * Capped so a long custom range cannot render thousands of columns.
     *
     * @return array<int, array{label: string, deposits: float, withdrawals: float, ggr: float}>
     */
    private function trend(array $filters): array
    {
        $days = (int) min(31, max(1, (int) $filters['from']->diffInDays($filters['to']) + 1));
        $start = $filters['to']->startOfDay()->subDays($days - 1);

        $wallet = WalletTransaction::query()
            ->selectRaw('DATE(created_at) AS day, type, COALESCE(SUM(ABS(amount)), 0) AS total')
            ->whereIn('type', ['deposit', 'withdrawal'])
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $filters['to']])
            ->groupByRaw('DATE(created_at), type')
            ->get()
            ->groupBy('day');

        $rounds = GameRound::query()
            ->selectRaw('DATE(created_at) AS day, COALESCE(SUM(stake), 0) AS stake, COALESCE(SUM(payout), 0) AS payout')
            ->whereBetween('created_at', [$start, $filters['to']])
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy('day');

        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $start->addDays($offset);
            $key = $day->format('Y-m-d');
            $walletDay = $wallet->get($key);
            $roundDay = $rounds->get($key);

            $series[] = [
                'label' => $day->format('M j'),
                'deposits' => (float) ($walletDay?->firstWhere('type', 'deposit')?->total ?? 0),
                'withdrawals' => (float) ($walletDay?->firstWhere('type', 'withdrawal')?->total ?? 0),
                'ggr' => (float) (($roundDay?->stake ?? 0) - ($roundDay?->payout ?? 0)),
            ];
        }

        return $series;
    }

    private function filters(Request $request): array
    {
        $preset = in_array($request->string('preset')->toString(), ['today', 'week', 'month', 'quarter', 'year', 'all', 'custom'], true)
            ? $request->string('preset')->toString()
            : 'month';

        $today = CarbonImmutable::now()->startOfDay();
        $isDate = static fn (string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        if ($preset === 'custom' && $isDate($request->string('from')->toString()) && $isDate($request->string('to')->toString())) {
            $from = CarbonImmutable::parse($request->string('from')->toString())->startOfDay();
            $to = CarbonImmutable::parse($request->string('to')->toString())->endOfDay();
        } else {
            $preset = $preset === 'custom' ? 'month' : $preset;
            [$from, $to] = match ($preset) {
                'today' => [$today, $today->endOfDay()],
                'week' => [$today->subDays(6), $today->endOfDay()],
                'quarter' => [$today->subDays(89), $today->endOfDay()],
                'year' => [$today->startOfYear(), $today->endOfDay()],
                'all' => [CarbonImmutable::create(2000, 1, 1), $today->endOfDay()],
                default => [$today->subDays(29), $today->endOfDay()],
            };
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        $sort = array_key_exists($request->string('sort')->toString(), self::SORTS)
            ? $request->string('sort')->toString()
            : 'house_ggr';

        return [
            'preset' => $preset,
            'from' => $from,
            'to' => $to,
            'search' => $request->string('search')->trim()->limit(120)->toString(),
            'segment' => array_key_exists($request->string('segment')->toString(), self::SEGMENTS)
                ? $request->string('segment')->toString()
                : '',
            'status' => in_array($request->string('status')->toString(), ['active', 'inactive'], true)
                ? $request->string('status')->toString()
                : '',
            'sort' => $sort,
            'direction' => $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc',
            'per_page' => in_array($request->integer('per_page'), [10, 25, 50, 100], true) ? $request->integer('per_page') : 25,
        ];
    }
}
