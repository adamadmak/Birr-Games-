<?php

namespace App\Http\Controllers\Admin;

use App\Models\Game;
use App\Models\GameRound;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CasinoTransactionController
{
    public function index(Request $request): View
    {
        $tab = in_array($request->string('tab')->toString(), ['bets', 'payouts', 'rollbacks'], true)
            ? $request->string('tab')->toString()
            : 'bets';
        $search = trim($request->string('search')->toString());
        $perPage = in_array((int) $request->input('per_page', 10), [10, 25, 50], true)
            ? (int) $request->input('per_page', 10)
            : 10;
        $games = Game::query()->orderBy('title')->pluck('title', 'slug');

        if ($tab === 'bets') {
            $query = GameRound::query()->with('user:id,name,phone');
            $this->filterRounds($query, $request, $search);
            $sort = match ($request->string('sort')->toString()) {
                'name' => 'game', 'amount' => 'stake', 'paid_amount' => 'payout', default => 'created_at',
            };
            $query->orderBy($sort, $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc');
            $transactions = $query->paginate($perPage)->withQueryString();
        } else {
            $type = $tab === 'payouts' ? 'payout' : 'rollback';
            $query = WalletTransaction::query()->with('user:id,name,phone')->where('type', $type);
            $this->filterLedger($query, $request, $search);
            $sort = match ($request->string('sort')->toString()) {
                'amount', 'withdrawable' => 'amount', 'name' => 'source_id', default => 'created_at',
            };
            $query->orderBy($sort, $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc');
            $transactions = $query->paginate($perPage)->withQueryString();
        }

        $roundIds = $tab === 'bets'
            ? collect()
            : $transactions->getCollection()->where('source_type', 'game_round')->pluck('source_id')->filter();
        $roundGames = GameRound::query()->whereIn('id', $roundIds)->pluck('game', 'id');

        return view('admin.casino.transactions', compact('tab', 'search', 'transactions', 'games', 'roundGames'));
    }

    private function filterRounds(Builder $query, Request $request, string $search): void
    {
        $query->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
            ->where('game', 'like', "%{$search}%")
            ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
            ->when($request->filled('game'), fn (Builder $q) => $q->where('game', $request->string('game')->toString()))
            ->when($request->filled('date'), fn (Builder $q) => $q->whereDate('created_at', $request->date('date')))
            ->when($request->filled('status') && $request->string('status')->toString() !== 'success', fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when($request->string('payout_status')->toString() === 'won', fn (Builder $q) => $q->where('payout', '>', 0))
            ->when($request->string('payout_status')->toString() === 'lost', fn (Builder $q) => $q->where('payout', 0));
    }

    private function filterLedger(Builder $query, Request $request, string $search): void
    {
        $query->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
            ->where('transaction_id', 'like', "%{$search}%")
            ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
            ->when($request->filled('date'), fn (Builder $q) => $q->whereDate('created_at', $request->date('date')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('game'), function (Builder $q) use ($request): void {
                $roundIds = GameRound::query()->where('game', $request->string('game')->toString())->pluck('id');
                $q->where('source_type', 'game_round')->whereIn('source_id', $roundIds);
            });
    }
}
