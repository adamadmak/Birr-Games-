<?php

namespace App\Http\Controllers\Admin;

use App\Models\Game;
use App\Models\GameRound;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CasinoReportController
{
    public function index(Request $request): View
    {
        $tab = in_array($request->string('tab')->toString(), ['summary', 'game', 'user', 'user-game'], true)
            ? $request->string('tab')->toString()
            : 'summary';
        [$from, $to, $period] = $this->dateRange($request);
        $search = trim($request->string('search')->toString());

        $gameNames = Game::query()->pluck('title', 'slug');
        $roundSummary = GameRound::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) AS bet_count, COALESCE(SUM(stake), 0) AS bet_amount, COALESCE(SUM(payout), 0) AS payout_amount')
            ->first();
        $rollbackSummary = WalletTransaction::query()
            ->where('type', 'rollback')
            ->where('status', 'success')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(ABS(amount)), 0) AS total')
            ->first();

        $summary = [
            'bet_count' => (int) $roundSummary->bet_count,
            'bet_amount' => (float) $roundSummary->bet_amount,
            'payout_amount' => (float) $roundSummary->payout_amount,
            'rollback_amount' => (float) $rollbackSummary->total,
        ];
        $summary['ggr'] = $summary['bet_amount'] - $summary['payout_amount'];

        $rows = collect();
        if ($tab !== 'summary') {
            $rounds = GameRound::query()
                ->with('user:id,name,first_name,last_name,phone,username')
                ->whereBetween('created_at', [$from, $to])
                ->get();
            $rollbacksByRound = WalletTransaction::query()
                ->where('type', 'rollback')
                ->where('status', 'success')
                ->where('source_type', 'game_round')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('source_id, SUM(ABS(amount)) AS total')
                ->groupBy('source_id')
                ->pluck('total', 'source_id');
            $rows = $this->aggregate($rounds, $rollbacksByRound, $gameNames, $tab);
            $rows = $this->filterRows($rows, $request, $search, $tab);
            $rows = $this->sortRows($rows, $request);
        }

        return view('admin.casino.report', [
            'tab' => $tab,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'search' => $search,
            'summary' => $summary,
            'rows' => $this->paginate($rows, $request),
            'games' => $gameNames->sort()->values(),
        ]);
    }

    private function dateRange(Request $request): array
    {
        $period = $request->string('period')->toString();
        $today = Carbon::today();

        if ($request->filled('date_from') || $request->filled('date_to')) {
            try {
                $from = Carbon::parse($request->input('date_from', $request->input('date_to')))->startOfDay();
                $to = Carbon::parse($request->input('date_to', $request->input('date_from')))->endOfDay();
            } catch (\Throwable) {
                $from = $today->copy()->startOfDay();
                $to = $today->copy()->endOfDay();
            }
            return [$from, $to, 'custom'];
        }

        return match ($period) {
            'week' => [$today->copy()->startOfWeek(), $today->copy()->endOfDay(), 'week'],
            'month' => [$today->copy()->startOfMonth(), $today->copy()->endOfDay(), 'month'],
            default => [$today->copy()->startOfDay(), $today->copy()->endOfDay(), 'today'],
        };
    }

    private function aggregate(Collection $rounds, Collection $rollbacks, Collection $games, string $tab): Collection
    {
        $grouped = $rounds->groupBy(function (GameRound $round) use ($tab): string {
            $date = $round->created_at->format('Y-m-d');
            return match ($tab) {
                'game' => $round->game.'|'.$date,
                'user' => $round->user_id.'|'.$date,
                default => $round->user_id.'|'.$round->game.'|'.$date,
            };
        });

        return $grouped->map(function (Collection $items) use ($rollbacks, $games): array {
            /** @var GameRound $first */
            $first = $items->first();
            $betAmount = $items->sum('stake');
            $payoutAmount = $items->sum('payout');
            $rollbackAmount = $items->sum(fn (GameRound $round) => (int) $rollbacks->get($round->id, 0));
            $ggr = $betAmount - $payoutAmount;

            return [
                'user_id' => $first->user_id,
                'name' => $first->user?->name ?? 'Deleted member',
                'phone' => $first->user?->phone ?: '-',
                'game' => $games->get($first->game, str($first->game)->replace('-', ' ')->title()->toString()),
                'game_slug' => $first->game,
                'bet_amount' => $betAmount,
                'payout_amount' => $payoutAmount,
                'rollback_amount' => $rollbackAmount,
                'rollback_count' => $items->filter(fn (GameRound $round) => (int) $rollbacks->get($round->id, 0) > 0)->count(),
                'bet_count' => $items->count(),
                'payout_count' => $items->where('payout', '>', 0)->count(),
                'ggr' => $ggr,
                'ggr_percentage' => $betAmount > 0 ? $ggr / $betAmount : 0,
                'date' => $first->created_at->format('Y-m-d'),
            ];
        })->values();
    }

    private function filterRows(Collection $rows, Request $request, string $search, string $tab): Collection
    {
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(function (array $row) use ($needle, $tab): bool {
                $haystack = $tab === 'game'
                    ? $row['game']
                    : implode(' ', [$row['name'], $row['phone'], $tab === 'user-game' ? $row['game'] : '']);
                return str_contains(mb_strtolower($haystack), $needle);
            });
        }

        if ($request->filled('game')) {
            $game = mb_strtolower($request->string('game')->toString());
            $rows = $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['game']), $game));
        }

        foreach (['bet_amount', 'bet_count', 'ggr', 'ggr_percentage', 'payout_amount', 'payout_count', 'rollback_amount', 'rollback_count'] as $field) {
            if ($request->filled($field.'_min')) {
                $minimum = (float) $request->input($field.'_min');
                $rows = $rows->where($field, '>=', $minimum);
            }
            if ($request->filled($field.'_max')) {
                $maximum = (float) $request->input($field.'_max');
                $rows = $rows->where($field, '<=', $maximum);
            }
        }

        return $rows->values();
    }

    private function sortRows(Collection $rows, Request $request): Collection
    {
        $sort = $request->string('sort')->toString();
        $allowed = ['name', 'game', 'phone', 'bet_amount', 'payout_amount', 'rollback_amount', 'bet_count', 'payout_count', 'ggr', 'ggr_percentage', 'date'];
        $sort = in_array($sort, $allowed, true) ? $sort : 'date';
        $descending = $request->string('direction')->toString() !== 'asc';

        return $rows->sortBy($sort, SORT_REGULAR, $descending)->values();
    }

    private function paginate(Collection $rows, Request $request): LengthAwarePaginator
    {
        $perPage = in_array((int) $request->input('per_page', 10), [10, 25, 50], true)
            ? (int) $request->input('per_page', 10)
            : 10;
        $page = max(1, (int) $request->input('page', 1));

        return (new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        ))->withQueryString();
    }
}
