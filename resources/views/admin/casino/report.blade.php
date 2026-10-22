@extends('layouts.admin')
@section('title', 'Casino Report')
@section('content')
@php
    $tabLabels = ['summary' => 'Summary Report', 'game' => 'Game Report', 'user' => 'User Report', 'user-game' => 'User Game Report'];
    $queryFor = fn (array $changes = []) => array_filter(array_merge(request()->except('page'), $changes), fn ($value) => $value !== null && $value !== '');
    $sortUrl = function (string $field) use ($queryFor) {
        $direction = request('sort') === $field && request('direction') !== 'asc' ? 'asc' : 'desc';
        return route('admin.casino.report', $queryFor(['sort' => $field, 'direction' => $direction]));
    };
@endphp

<div class="admin-page-heading casino-page-heading">
    <div><h1>Casino Report</h1></div>
</div>

<nav class="casino-tabs" aria-label="Casino report views">
    @foreach($tabLabels as $key => $label)
        <a class="{{ $tab === $key ? 'active' : '' }}" href="{{ route('admin.casino.report', $queryFor(['tab' => $key, 'page' => null, 'search' => null, 'sort' => null, 'direction' => null])) }}">{{ $label }}</a>
    @endforeach
</nav>

@if($tab === 'summary')
    <section class="casino-summary-head">
        <div>
            <h2>Summary Report</h2>
            <p>Report period: {{ $from->format('n/j/Y') }} - {{ $to->format('n/j/Y') }}</p>
            <div class="casino-quick-ranges">
                <a class="{{ $period === 'today' ? 'active' : '' }}" href="{{ route('admin.casino.report', ['tab' => 'summary', 'period' => 'today']) }}">Today</a>
                <a class="{{ $period === 'week' ? 'active' : '' }}" href="{{ route('admin.casino.report', ['tab' => 'summary', 'period' => 'week']) }}">This Week</a>
                <a class="{{ $period === 'month' ? 'active' : '' }}" href="{{ route('admin.casino.report', ['tab' => 'summary', 'period' => 'month']) }}">This Month</a>
            </div>
        </div>
        <form class="casino-date-range" method="GET">
            <input type="hidden" name="tab" value="summary">
            <label><i class="fa-regular fa-calendar"></i><input type="date" name="date_from" value="{{ $from->format('Y-m-d') }}"><span>-</span><input type="date" name="date_to" value="{{ $to->format('Y-m-d') }}"></label>
            <button type="submit">Search</button>
            <a href="{{ route('admin.casino.report') }}"><i class="fa-regular fa-circle-xmark"></i> Reset</a>
        </form>
    </section>

    <section class="casino-activity-card">
        <h2><i class="fa-solid fa-dice"></i> Betting Activity</h2>
        <div class="casino-report-metrics">
            <article><span class="blue"><i class="fa-solid fa-chart-simple"></i></span><small>Bet Count</small><strong>{{ number_format($summary['bet_count']) }}</strong><p>Number of bets placed</p></article>
            <article><span class="violet"><i class="fa-solid fa-coins"></i></span><small>Total Bet Amount</small><strong>{{ \App\Support\Money::format($summary['bet_amount'], 2) }}</strong><p>Money wagered</p></article>
            <article><span class="green"><i class="fa-solid fa-arrow-trend-up"></i></span><small>Total Payouts</small><strong>{{ \App\Support\Money::format($summary['payout_amount'], 2) }}</strong><p>Money paid out to winners</p></article>
            <article><span class="orange"><i class="fa-solid fa-rotate-left"></i></span><small>Rollback Amount</small><strong>{{ \App\Support\Money::format($summary['rollback_amount'], 2) }}</strong><p>Cancelled or rollback bets</p></article>
            <article><span class="teal"><i class="fa-solid fa-sack-dollar"></i></span><small>GGR</small><strong>{{ \App\Support\Money::format($summary['ggr'], 2) }}</strong><p>Gross Gaming Revenue</p></article>
        </div>
    </section>
@else
    <section class="casino-list-toolbar">
        <form method="GET" class="casino-search-form">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="search" value="{{ $search }}" placeholder="{{ $tab === 'game' ? 'Search games...' : ($tab === 'user' ? 'Search by Phone Number...' : 'Search by Games, Phone Number...') }}">
        </form>
        <button type="button" class="casino-dark-button" data-member-modal-open="casino-report-filter"><i class="fa-solid fa-filter"></i> Game Report Filter</button>
    </section>

    <section class="casino-table-card">
        <div class="casino-table-scroll">
            <table class="casino-data-table">
                <thead><tr>
                    <th>#</th>
                    @if($tab !== 'game')<th><a href="{{ $sortUrl('name') }}">Name <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th><th><a href="{{ $sortUrl('phone') }}">Phone Number <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>@endif
                    @if($tab !== 'user')<th><a href="{{ $sortUrl('game') }}">{{ $tab === 'user-game' ? 'Game Name' : 'Name' }} <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>@endif
                    <th><a href="{{ $sortUrl('bet_amount') }}">Bet Amount <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>
                    <th><a href="{{ $sortUrl('payout_amount') }}">Payout Amount <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>
                    <th><a href="{{ $sortUrl('rollback_amount') }}">Rollback Amount <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>
                    <th><a href="{{ $sortUrl('bet_count') }}">Bet Count</a></th>
                    <th><a href="{{ $sortUrl('payout_count') }}">Payout Count</a></th>
                    <th><a href="{{ $sortUrl('ggr') }}">GGR</a></th>
                    <th><a href="{{ $sortUrl('ggr_percentage') }}">GGR Percentage</a></th>
                    <th><a href="{{ $sortUrl('date') }}">Date <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>
                </tr></thead>
                <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td>{{ ($rows->currentPage() - 1) * $rows->perPage() + $loop->iteration }}</td>
                        @if($tab !== 'game')
                            <td><strong>{{ $row['name'] }}</strong></td>
                            <td>@if($row['user_id'])<a class="casino-phone-link" href="{{ route('admin.members.show', $row['user_id']) }}">{{ $row['phone'] }}</a>@else{{ $row['phone'] }}@endif</td>
                        @endif
                        @if($tab !== 'user')<td><strong>{{ $row['game'] }}</strong></td>@endif
                        <td>{{ \App\Support\Money::format($row['bet_amount'], 2) }}</td>
                        <td>{{ \App\Support\Money::format($row['payout_amount'], 2) }}</td>
                        <td>{{ \App\Support\Money::format($row['rollback_amount'], 2) }}</td>
                        <td>{{ number_format($row['bet_count']) }}</td>
                        <td>{{ number_format($row['payout_count']) }}</td>
                        <td class="{{ $row['ggr'] < 0 ? 'casino-negative' : 'casino-positive' }}">{{ \App\Support\Money::format($row['ggr'], 2) }}</td>
                        <td>{{ number_format($row['ggr_percentage'] * 100, 2) }}%</td>
                        <td>{{ \Carbon\Carbon::parse($row['date'])->format('n/j/Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="12"><div class="casino-empty"><i class="fa-solid fa-chart-column"></i><strong>No report records found</strong><span>Game activity matching these filters will appear here.</span></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.casino._pagination', ['paginator' => $rows])
    </section>

    <dialog id="casino-report-filter" class="casino-filter-dialog" data-member-modal>
        <form method="GET">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <header><div><h3>Game Report Filter</h3><p>Select your filters here</p></div><button type="button" data-member-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header>
            <div class="casino-filter-grid">
                <label class="wide">Game<select name="game"><option value="">Select game</option>@foreach($games as $game)<option value="{{ $game }}" @selected(request('game') === $game)>{{ $game }}</option>@endforeach</select></label>
                @foreach(['bet_amount' => 'Bet Amount', 'bet_count' => 'Bet Count', 'ggr' => 'GGR', 'ggr_percentage' => 'GGR Percentage', 'payout_amount' => 'Payout Amount', 'payout_count' => 'Payout Count', 'rollback_amount' => 'Rollback Amount', 'rollback_count' => 'Rollback Count'] as $field => $label)
                    <label>{{ $label }} Max<input type="number" step="any" name="{{ $field }}_max" value="{{ request($field.'_max') }}" placeholder="Maximum"></label>
                    <label>{{ $label }} Min<input type="number" step="any" name="{{ $field }}_min" value="{{ request($field.'_min') }}" placeholder="Minimum"></label>
                @endforeach
                <label>Date From<input type="date" name="date_from" value="{{ request('date_from') }}"></label>
                <label>Date To<input type="date" name="date_to" value="{{ request('date_to') }}"></label>
            </div>
            <footer><a href="{{ route('admin.casino.report', ['tab' => $tab]) }}">Reset Filters</a><button type="submit">Apply Filters</button><button class="secondary" type="button" data-member-modal-close>Close</button></footer>
        </form>
    </dialog>
@endif
@endsection
