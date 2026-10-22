@extends('layouts.admin')
@section('title', 'Bet Transactions')
@section('content')
@php
    $queryFor = fn (array $changes = []) => array_filter(array_merge(request()->except('page'), $changes), fn ($value) => $value !== null && $value !== '');
    $sortUrl = function (string $field) use ($queryFor) {
        $direction = request('sort') === $field && request('direction') !== 'asc' ? 'asc' : 'desc';
        return route('admin.casino.transactions', $queryFor(['sort' => $field, 'direction' => $direction]));
    };
    $gameTitle = fn (string $slug) => $games->get($slug, str($slug)->replace('-', ' ')->title()->toString());
@endphp

<div class="admin-page-heading casino-page-heading"><div><h1>Bet Transactions</h1></div></div>

<nav class="casino-tabs" aria-label="Casino transaction types">
    @foreach(['bets' => 'Bets', 'payouts' => 'Payouts', 'rollbacks' => 'Rollbacks'] as $key => $label)
        <a class="{{ $tab === $key ? 'active' : '' }}" href="{{ route('admin.casino.transactions', ['tab' => $key]) }}">{{ $label }}</a>
    @endforeach
</nav>

<form class="casino-transaction-filters" method="GET">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <label class="casino-search-box"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="search" value="{{ $search }}" placeholder="Search by Phone Number..."></label>
    <details class="casino-filter-menu"><summary><i class="fa-regular fa-calendar"></i> Date Filter</summary><div><label>Date<input type="date" name="date" value="{{ request('date') }}"></label><button>Apply</button></div></details>
    <details class="casino-filter-menu"><summary><i class="fa-regular fa-circle-plus"></i> Game</summary><div><label>Game<select name="game"><option value="">All games</option>@foreach($games as $slug => $title)<option value="{{ $slug }}" @selected(request('game') === $slug)>{{ $title }}</option>@endforeach</select></label><button>Apply</button></div></details>
    <details class="casino-filter-menu"><summary><i class="fa-regular fa-circle-plus"></i> Status</summary><div><label>Status<select name="status"><option value="">All statuses</option>@foreach($tab === 'bets' ? ['success'] : ['success', 'pending', 'rejected', 'cancelled'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $tab === 'bets' ? 'Complete' : ucfirst($status) }}</option>@endforeach</select></label><button>Apply</button></div></details>
    @if($tab === 'bets')<details class="casino-filter-menu"><summary><i class="fa-regular fa-circle-plus"></i> Payout Status</summary><div><label>Payout Status<select name="payout_status"><option value="">All outcomes</option><option value="won" @selected(request('payout_status') === 'won')>Won</option><option value="lost" @selected(request('payout_status') === 'lost')>Lost</option></select></label><button>Apply</button></div></details>@endif
    <button class="casino-filter-submit" type="submit">Search</button>
    @if(request()->except('tab'))<a class="casino-filter-reset" href="{{ route('admin.casino.transactions', ['tab' => $tab]) }}">Reset</a>@endif
</form>

<section class="casino-table-card">
    <div class="casino-table-scroll">
        <table class="casino-data-table transaction-table">
            <thead><tr>
                <th><input type="checkbox" aria-label="Select all transactions"></th><th>#</th>
                <th><a href="{{ $sortUrl('name') }}">Name <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>
                <th>Phone Number</th><th>Status</th>
                <th><a href="{{ $sortUrl('amount') }}">Amount <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>
                <th><a href="{{ $sortUrl($tab === 'bets' ? 'paid_amount' : 'withdrawable') }}">{{ $tab === 'bets' ? 'Paid Amount' : 'Withdrawable' }} <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>
                <th><a href="{{ $sortUrl('date') }}">Date <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>
            </tr></thead>
            <tbody>
            @forelse($transactions as $transaction)
                @php
                    $roundSlug = $tab === 'bets' ? $transaction->game : $roundGames->get($transaction->source_id, 'game');
                    $won = $tab === 'bets' && $transaction->payout > 0;
                    $statusLabel = $tab === 'bets' ? ($won ? 'Won' : 'Lost') : ($tab === 'payouts' ? ($transaction->status === 'success' ? 'Paid' : ucfirst($transaction->status)) : ($transaction->status === 'success' ? 'Complete' : ucfirst($transaction->status)));
                    $amount = $tab === 'bets' ? $transaction->stake : abs($transaction->amount);
                    $secondaryAmount = $tab === 'bets' ? $transaction->payout : abs($transaction->amount);
                @endphp
                <tr>
                    <td><input type="checkbox" aria-label="Select transaction {{ $transaction->id }}"></td>
                    <td>{{ ($transactions->currentPage() - 1) * $transactions->perPage() + $loop->iteration }}</td>
                    <td><strong>{{ $gameTitle($roundSlug) }}</strong></td>
                    <td>@if($transaction->user)<a class="casino-phone-link" href="{{ route('admin.members.show', $transaction->user_id) }}">{{ $transaction->user->phone ?: '-' }}</a>@else-@endif</td>
                    <td><span class="casino-transaction-status {{ $won || ($tab !== 'bets' && $transaction->status === 'success') ? 'success' : 'danger' }}"><i></i>{{ $statusLabel }}</span></td>
                    <td>{{ \App\Support\Money::format($amount, 2) }}</td>
                    <td>{{ \App\Support\Money::format($secondaryAmount, 2) }}</td>
                    <td>{{ $transaction->created_at->format('m/d/Y h:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="casino-empty"><i class="fa-solid fa-clock-rotate-left"></i><strong>No recent {{ $tab }} found</strong><span>Provider callbacks and local game activity will appear here.</span></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('admin.casino._pagination', ['paginator' => $transactions])
</section>
@endsection
