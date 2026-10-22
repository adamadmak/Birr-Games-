@extends('layouts.admin')
@section('title', 'Dashboard')
@section('content')
@php
    /* Direction is carried by the arrow glyph as well as the colour, so the
       chip still reads for anyone who cannot separate red from green. */
    $deltaChip = function (?float $value) {
        if ($value === null) {
            return '<span class="metric-delta is-flat" title="No activity in the previous period to compare against">—</span>';
        }
        $up = $value >= 0;
        $cls = $up ? 'is-up' : 'is-down';
        $arrow = $up ? 'M12 5v14M5 12l7-7 7 7' : 'M12 19V5M19 12l-7 7-7-7';
        $sign = $up ? '+' : '';
        return '<span class="metric-delta '.$cls.'">'
            .'<svg viewBox="0 0 24 24" width="9" height="9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$arrow.'"/></svg>'
            .$sign.number_format($value, 1).'%</span>';
    };
@endphp
@php
    /* The period the operator is looking at, expressed the way each destination
       filters. Without this a drill-down silently changed the window — the cash
       cards below were pinned to 'month' whatever the dashboard was showing, so
       clicking Deposits while reading Today answered a different question. */
    $windowDates = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    $reportWindow = ['preset' => 'custom'] + $windowDates;
    $ledgerWindow = ['created_after' => $from->format('Y-m-d'), 'created_before' => $to->format('Y-m-d')];
@endphp
@push('styles')
    <link rel="stylesheet" href="/css/lucy-dashboard.css?v={{ @filemtime(public_path('css/lucy-dashboard.css')) ?: time() }}">
@endpush

@php
    /* The split the position is drawn from. Guarded: a period with no play at
       all would otherwise divide by zero and take the page down. */
    $staked = (float) $stats['volume'];
    $paid = (float) $stats['payouts'];
    $paidShare = $staked > 0 ? max(0, min(100, $paid / $staked * 100)) : 0;
    $holdPct = $staked > 0 ? 100 - ($paid / $staked * 100) : null;
@endphp

<div class="admin-page-heading">
    <div><p>Overview</p><h1>Dashboard</h1><span>Live platform performance in Ethiopian Birr</span></div>
    <a class="admin-view-site" href="{{ route('home') }}" data-testid="view-player-site">View player site <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
</div>

<div class="ops-dash">

<section class="ops-period">
    <div class="ops-period-dates">{{ $from->format('M j, Y') }} — {{ $to->format('M j, Y') }}</div>
    <nav class="ops-period-tabs">
        @foreach (['today' => 'Today', '7days' => 'Last 7 days', 'month' => 'This month', 'year' => 'This year'] as $value => $label)
            <a @if($range === $value) aria-current="page" @endif href="{{ route('admin.dashboard', ['range' => $value]) }}">{{ $label }}</a>
        @endforeach
    </nav>
    <form method="GET" action="{{ route('admin.dashboard') }}">
        <input type="hidden" name="range" value="custom">
        <label>From<input type="date" name="from" value="{{ $from->format('Y-m-d') }}"></label>
        <label>To<input type="date" name="to" value="{{ $to->format('Y-m-d') }}"></label>
        <button type="submit">Apply</button>
        <a href="{{ route('admin.dashboard') }}">Reset</a>
    </form>
</section>

{{-- The house's position for the period, and the two flows that produce it. --}}
<section class="ops-position" data-testid="metric-ggr">
    <div class="ops-position-figure">
        <div class="ops-position-label">Gross gaming revenue {!! $deltaChip($deltas['ggr'] ?? null) !!}</div>
        <p class="ops-position-value">{{ \App\Support\Money::format($stats['ggr']) }}</p>
        <div class="ops-position-note">
            @if ($holdPct === null)
                No rounds played in this period
            @elseif ($holdPct < 0)
                {{-- A losing period is a real outcome, not a negative percentage
                     of "kept". Say what actually happened. --}}
                Players are ahead by <b>{{ number_format(abs($holdPct), 1) }}%</b> of everything staked
            @else
                The house kept <b>{{ number_format($holdPct, 1) }}%</b> of everything staked
            @endif
        </div>
    </div>
    <div class="ops-split">
        <div class="ops-split-bar" style="--paid-share: {{ $paidShare }}%" role="img" aria-label="Of {{ \App\Support\Money::format($staked) }} staked, {{ \App\Support\Money::format($paid) }} was paid out">
            <span class="is-kept"></span>
            <span class="is-paid"></span>
        </div>
        <div class="ops-split-keys">
            <div><span>Staked</span><b>{{ \App\Support\Money::format($stats['volume']) }}</b></div>
            <div><span>Paid out</span><b>{{ \App\Support\Money::format($stats['payouts']) }}</b></div>
            <div><span>Rounds</span><b>{{ number_format($stats['bets']) }}</b></div>
            <div><span>Average bet</span><b>{{ \App\Support\Money::format($stats['average']) }}</b></div>
        </div>
    </div>
</section>

@if(auth()->user()->hasAdminPermission('transactions.view'))
{{-- Cash, as a ledger. One right edge, tabular figures, so the column scans. --}}
<section class="ops-ledger" data-testid="dashboard-cash-strip">
    <div class="ops-ledger-head">
        <h2>Cash</h2>
        <span>In this period</span>
    </div>

    <a class="ops-row is-in" href="{{ route('admin.transactions.index', $ledgerWindow + ['type' => 'deposit', 'status' => 'success']) }}">
        <span class="ops-row-dot"></span>
        <span class="ops-row-name"><strong>Deposits</strong><small>{{ number_format($stats['depositCount']) }} settled</small></span>
        <span class="ops-row-amount">{{ \App\Support\Money::format($stats['deposits'], 2) }}</span>
    </a>
    <a class="ops-row is-out" href="{{ route('admin.transactions.index', $ledgerWindow + ['type' => 'withdrawal', 'status' => 'success']) }}">
        <span class="ops-row-dot"></span>
        <span class="ops-row-name"><strong>Withdrawals</strong><small>{{ number_format($stats['withdrawalCount']) }} paid out</small></span>
        <span class="ops-row-amount">{{ \App\Support\Money::format($stats['withdrawals'], 2) }}</span>
    </a>
    <a class="ops-row" href="{{ route('admin.players.report', $reportWindow) }}">
        <span class="ops-row-dot"></span>
        <span class="ops-row-name"><strong>Net cash in</strong><small>Deposits less withdrawals</small></span>
        <span class="ops-row-amount">{{ \App\Support\Money::format($stats['netCash'], 2) }}</span>
    </a>

    <div class="ops-ledger-break">Waiting on someone</div>

    <a class="ops-row" href="{{ route('admin.agents.withdrawals') }}">
        <span class="ops-row-dot"></span>
        <span class="ops-row-name"><strong>Payouts awaiting agents</strong><small>{{ number_format($stats['pendingPayoutCount']) }} open order(s)</small></span>
        <span class="ops-row-amount">{{ \App\Support\Money::format($stats['pendingPayoutTotal'], 2) }}</span>
    </a>
    <a class="ops-row" href="{{ route('admin.agents.reports') }}">
        <span class="ops-row-dot"></span>
        <span class="ops-row-name"><strong>Agent float</strong><small>Credit held by active agents</small></span>
        <span class="ops-row-amount">{{ \App\Support\Money::format($stats['agentFloat'], 2) }}</span>
    </a>
</section>
@endif

{{-- Counts, kept deliberately quiet: they are context, not the job. --}}
<section class="ops-counts">
    <a class="ops-count" href="{{ route('admin.members.index') }}" data-testid="metric-total-members"><span>Members</span><b>{{ number_format($stats['members']) }}</b></a>
    <a class="ops-count" href="{{ route('admin.players.report', $reportWindow + ['segment' => 'bettors']) }}"><span>Players who bet</span><b>{{ number_format($stats['activePlayers']) }}</b></a>
    <a class="ops-count" href="{{ route('admin.members.index') }}"><span>New sign-ups</span><b>{{ number_format($stats['newMembers']) }}</b></a>
    <a class="ops-count" href="{{ route('admin.promotions.index') }}" data-testid="metric-active-promotions"><span>Live promotions</span><b>{{ number_format($stats['promotions']) }}</b></a>
    <a class="ops-count is-live" href="{{ route('admin.operations.index') }}" data-testid="metric-online-users"><span>Online now</span><b>{{ number_format($stats['onlineUsers']) }}</b></a>
    <span class="ops-count"><span>Player balances</span><b>{{ \App\Support\Money::format($stats['playerBalances']) }}</b></span>
    <a class="ops-count" href="{{ route('admin.rounds.index', $windowDates) }}" data-testid="metric-total-bets"><span>Rounds</span><b>{{ number_format($stats['bets']) }}</b></a>
    <span class="ops-count"><span>Return to player</span><b>{{ $stats['rtp'] === null ? '—' : number_format($stats['rtp'], 1).'%' }}</b></span>
</section>

<section class="admin-chart-grid">
    <article class="admin-panel chart-panel" data-testid="dashboard-trend-chart">
        <div class="panel-title">
            <div><p>Trend</p><h2>Volume &amp; gross gaming revenue</h2></div>
            <span class="chart-range">Last {{ $trend['days'] }} days</span>
        </div>
        <div class="chart-legend">
            <span><i style="background: var(--primary)"></i> Bet volume</span>
            <span><i style="background: var(--success)"></i> GGR</span>
        </div>
        @include('admin.partials.trend-chart', [
            'labels' => $trend['labels'],
            'format' => fn ($v) => \App\Support\Money::format($v),
            'series' => [
                ['key' => 'volume', 'name' => 'Volume', 'color' => 'var(--primary)', 'type' => 'area', 'data' => $trend['volume']],
                ['key' => 'ggr', 'name' => 'GGR', 'color' => 'var(--success)', 'type' => 'line', 'data' => $trend['ggr']],
            ],
        ])
    </article>

    <article class="admin-panel chart-panel" data-testid="dashboard-cashflow-chart">
        <div class="panel-title">
            <div><p>Cash flow</p><h2>Deposits vs withdrawals</h2></div>
            <span class="chart-range">Last {{ $trend['days'] }} days</span>
        </div>
        <div class="chart-legend">
            <span><i style="background: var(--info)"></i> Deposits</span>
            <span><i style="background: var(--warn)"></i> Withdrawals</span>
        </div>
        @include('admin.partials.trend-chart', [
            'labels' => $trend['labels'],
            'format' => fn ($v) => \App\Support\Money::format($v),
            'series' => [
                ['key' => 'deposits', 'name' => 'Deposits', 'color' => 'var(--info)', 'type' => 'area', 'data' => $trend['deposits']],
                ['key' => 'withdrawals', 'name' => 'Withdrawals', 'color' => 'var(--warn)', 'type' => 'line', 'data' => $trend['withdrawals']],
            ],
        ])
    </article>
</section>

<section class="admin-dashboard-grid">
    <article class="admin-panel quick-stats-panel">
        <div class="panel-title"><div><p>Snapshot</p><h2>Quick stats</h2></div><span>Live</span></div>
        <div class="quick-stat"><span>Average bet size</span><strong>{{ \App\Support\Money::format($stats['average']) }}<small>Per round</small></strong></div>
        <div class="quick-stat"><span>Total player balances</span><strong>{{ \App\Support\Money::format($stats['playerBalances']) }}<small>All player accounts</small></strong></div>
        <div class="quick-stat"><span>Database status</span><strong>Connected<small>MySQL live data</small></strong></div>
    </article>

    <article class="admin-panel recent-panel">
        <div class="panel-title"><div><p>Activity</p><h2>Recent rounds</h2></div><a href="{{ route('admin.casino.report') }}">View report →</a></div>
        <div class="compact-table">
            @forelse ($recentRounds as $round)
                <div><span class="table-avatar">{{ strtoupper(substr($round->user->name, 0, 1)) }}</span><p><strong>{{ $round->user->name }}</strong><small>{{ str($round->game)->replace('-', ' ')->title() }}</small></p><b>{{ \App\Support\Money::format($round->stake) }}</b><em class="{{ $round->payout > 0 ? 'positive' : '' }}">{{ \App\Support\Money::format($round->payout) }}</em></div>
            @empty
                <div class="admin-empty">No rounds in this report period.</div>
            @endforelse
        </div>
    </article>

    <article class="admin-panel players-panel">
        <div class="panel-title"><div><p>Balances</p><h2>Top players</h2></div><a href="{{ route('admin.members.index') }}">Manage →</a></div>
        @foreach ($topPlayers as $player)
            <div class="player-rank"><span>{{ $loop->iteration }}</span><p><strong>{{ $player->name }}</strong><small>{{ $player->username ? '@'.$player->username : 'No username' }}{{ $player->phone ? ' · '.$player->phone : '' }}</small></p><b>{{ \App\Support\Money::format($player->credits) }}</b></div>
        @endforeach
    </article>
</section>
</div>
@endsection
