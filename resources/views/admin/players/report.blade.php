@extends('layouts.admin')
@section('title', 'Player Accountability')

@section('content')
@php
    use App\Http\Controllers\Admin\PlayerReportController as Report;

    $money = fn ($amount) => \App\Support\Money::format($amount, 2);
    $signed = fn ($amount) => ($amount > 0 ? '+' : ($amount < 0 ? '−' : '')).\App\Support\Money::format(abs($amount), 2);
    $tone = fn ($amount) => $amount > 0 ? 'is-positive' : ($amount < 0 ? 'is-negative' : 'is-flat');
    $presets = ['today' => 'Today', 'week' => '7 days', 'month' => '30 days', 'quarter' => '90 days', 'year' => 'This year', 'all' => 'All time'];

    $sortUrl = function (string $field) use ($filters): string {
        $direction = $filters['sort'] === $field && $filters['direction'] === 'desc' ? 'asc' : 'desc';

        return route('admin.players.report', array_merge(request()->except(['sort', 'direction', 'page']), [
            'sort' => $field, 'direction' => $direction,
        ]));
    };
    $sortIcon = fn (string $field): string => $filters['sort'] === $field
        ? ($filters['direction'] === 'asc' ? 'fa-arrow-up-short-wide' : 'fa-arrow-down-wide-short')
        : 'fa-arrow-down-up-across-line';

    $carry = collect(request()->except(['page']))->filter(fn ($value) => is_scalar($value) && $value !== '');
    $chips = collect([
        'search' => $filters['search'] ? 'Search: '.$filters['search'] : null,
        'segment' => $filters['segment'] ? $segments[$filters['segment']] : null,
        'status' => $filters['status'] ? ucfirst($filters['status']).' wallets' : null,
    ])->filter();

    $peak = max(1, collect($trend)->flatMap(fn ($day) => [$day['deposits'], $day['withdrawals'], abs($day['ggr'])])->max());
    $columns = max(1, count($trend));
    $slot = 720 / $columns;
    $barWidth = min(18, max(3, $slot * 0.26));
    $axis = 96;
    $scale = fn ($value) => $peak > 0 ? ($value / $peak) * 74 : 0;
@endphp

<div class="admin-page-heading">
    <div>
        <p>Accountability</p>
        <h1>Player Report</h1>
        <span>Deposits, withdrawals, stakes and win/loss per member — {{ $filters['from']->format('M j, Y') }} to {{ $filters['to']->format('M j, Y') }}</span>
    </div>
    <a class="admin-view-site" href="{{ route('admin.players.report.export', request()->except(['page'])) }}" data-testid="player-report-export"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
</div>

<section class="report-toolbar">
    <div>
        <span>{{ number_format($summary['players']) }} member(s) · {{ number_format($summary['active_players']) }} placed bets</span>
        <nav class="range-tabs">
            @foreach($presets as $value => $label)
                <a class="{{ $filters['preset'] === $value ? 'active' : '' }}" href="{{ route('admin.players.report', array_merge(request()->except(['preset', 'from', 'to', 'page']), ['preset' => $value])) }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
    <form class="date-filter" method="GET" action="{{ route('admin.players.report') }}">
        <input type="hidden" name="preset" value="custom">
        @foreach(['search', 'segment', 'status', 'sort', 'direction', 'per_page'] as $keep)
            @if($filters[$keep] ?? null)<input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">@endif
        @endforeach
        <label>From<input type="date" name="from" value="{{ $filters['from']->format('Y-m-d') }}"></label>
        <label>To<input type="date" name="to" value="{{ $filters['to']->format('Y-m-d') }}"></label>
        <button type="submit">Apply</button>
        <a href="{{ route('admin.players.report') }}">Reset</a>
    </form>
</section>

<section class="metric-grid">
    <article class="metric-card metric-blue" data-testid="player-metric-deposits"><span class="metric-icon"><i class="fa-solid fa-arrow-down-to-line"></i></span><p>Player deposits</p><strong>{{ $money($summary['deposit_total']) }}</strong><small>{{ number_format($summary['deposit_count']) }} settled deposit(s)</small></article>
    <article class="metric-card metric-orange" data-testid="player-metric-withdrawals"><span class="metric-icon"><i class="fa-solid fa-arrow-up-from-bracket"></i></span><p>Player withdrawals</p><strong>{{ $money($summary['withdrawal_total']) }}</strong><small>{{ number_format($summary['withdrawal_count']) }} paid out</small></article>
    <article class="metric-card metric-teal" data-testid="player-metric-net"><span class="metric-icon"><i class="fa-solid fa-money-bill-trend-up"></i></span><p>Net cash in</p><strong>{{ $money($summary['net_deposit']) }}</strong><small>Deposits less withdrawals</small></article>
    <article class="metric-card metric-purple" data-testid="player-metric-staked"><span class="metric-icon"><i class="fa-solid fa-dice"></i></span><p>Total staked</p><strong>{{ $money($summary['stake_total']) }}</strong><small>{{ number_format($summary['bet_count']) }} bet(s) placed</small></article>
    <article class="metric-card metric-mint" data-testid="player-metric-won"><span class="metric-icon"><i class="fa-solid fa-trophy"></i></span><p>Player winnings</p><strong>{{ $money($summary['payout_total']) }}</strong><small>RTP {{ $summary['rtp'] === null ? '—' : number_format($summary['rtp'], 1).'%' }}</small></article>
    <article class="metric-card metric-green" data-testid="player-metric-ggr"><span class="metric-icon"><i class="fa-solid fa-building-columns"></i></span><p>House GGR</p><strong>{{ $money($summary['house_ggr']) }}</strong><small>Hold {{ $summary['hold'] === null ? '—' : number_format($summary['hold'], 1).'%' }}</small></article>
</section>

<section class="admin-panel report-panel player-chart-panel">
    <div class="panel-title">
        <div><p>Trend</p><h2>Cash flow and gaming revenue</h2></div>
        <div class="player-chart-legend">
            <span><i class="dot dot-in"></i> Deposits</span>
            <span><i class="dot dot-out"></i> Withdrawals</span>
            <span><i class="dot dot-ggr"></i> GGR</span>
        </div>
    </div>
    <div class="player-chart">
        <svg viewBox="0 0 720 180" role="img" aria-label="Daily deposits, withdrawals and gross gaming revenue" preserveAspectRatio="none">
            <line x1="0" y1="{{ $axis }}" x2="720" y2="{{ $axis }}" class="chart-axis"></line>
            @foreach($trend as $index => $day)
                @php
                    $center = ($index * $slot) + ($slot / 2);
                    $depositHeight = $scale($day['deposits']);
                    $withdrawalHeight = $scale($day['withdrawals']);
                @endphp
                @if($depositHeight > 0)
                    <rect class="chart-bar chart-bar-in" x="{{ round($center - $barWidth - 1, 2) }}" y="{{ round($axis - $depositHeight, 2) }}" width="{{ round($barWidth, 2) }}" height="{{ round($depositHeight, 2) }}" rx="2"><title>{{ $day['label'] }} · deposits {{ $money($day['deposits']) }}</title></rect>
                @endif
                @if($withdrawalHeight > 0)
                    <rect class="chart-bar chart-bar-out" x="{{ round($center + 1, 2) }}" y="{{ $axis }}" width="{{ round($barWidth, 2) }}" height="{{ round($withdrawalHeight, 2) }}" rx="2"><title>{{ $day['label'] }} · withdrawals {{ $money($day['withdrawals']) }}</title></rect>
                @endif
            @endforeach
            <polyline class="chart-line" points="@foreach($trend as $index => $day){{ round(($index * $slot) + ($slot / 2), 2) }},{{ round($axis - $scale($day['ggr']), 2) }} @endforeach"></polyline>
            @foreach($trend as $index => $day)
                <circle class="chart-point {{ $day['ggr'] < 0 ? 'is-negative' : '' }}" cx="{{ round(($index * $slot) + ($slot / 2), 2) }}" cy="{{ round($axis - $scale($day['ggr']), 2) }}" r="2.6"><title>{{ $day['label'] }} · GGR {{ $money($day['ggr']) }}</title></circle>
            @endforeach
        </svg>
        <footer>
            @foreach($trend as $index => $day)
                <span class="{{ $columns > 12 && $index % 3 !== 0 ? 'is-hidden' : '' }}">{{ $day['label'] }}</span>
            @endforeach
        </footer>
    </div>
    <div class="player-chart-facts">
        <div><small>Winners</small><strong class="is-positive">{{ number_format($summary['winners']) }}</strong></div>
        <div><small>Losers</small><strong class="is-negative">{{ number_format($summary['losers']) }}</strong></div>
        <div><small>Bonus credited</small><strong>{{ $money($summary['bonus_total']) }}</strong></div>
        <div><small>Wallet balances held</small><strong>{{ $money($summary['balance_total']) }}</strong></div>
        <div><small>Withdrawable held</small><strong>{{ $money($summary['withdrawable_total']) }}</strong></div>
    </div>
</section>

<section class="admin-panel report-panel">
    <div class="panel-title"><div><p>Ledger</p><h2>Member accountability</h2></div><span>{{ number_format($players->total()) }} member(s)</span></div>

    <form class="player-report-filter" method="GET" action="{{ route('admin.players.report') }}">
        <input type="hidden" name="preset" value="{{ $filters['preset'] }}">
        <input type="hidden" name="from" value="{{ $filters['from']->format('Y-m-d') }}">
        <input type="hidden" name="to" value="{{ $filters['to']->format('Y-m-d') }}">
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="direction" value="{{ $filters['direction'] }}">
        <label class="player-report-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Name, phone or username" aria-label="Search members"></label>
        <select name="segment" aria-label="Filter by segment">@foreach($segments as $value => $label)<option value="{{ $value }}" @selected($filters['segment'] === $value)>{{ $label }}</option>@endforeach</select>
        <select name="status" aria-label="Filter by wallet status">
            <option value="">Any wallet</option>
            <option value="active" @selected($filters['status'] === 'active')>Active only</option>
            <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive only</option>
        </select>
        <button type="submit">Filter</button>
        <a href="{{ route('admin.players.report', ['preset' => $filters['preset']]) }}">Clear</a>
    </form>

    @if($chips->isNotEmpty())
        <div class="player-report-chips">
            @foreach($chips as $key => $label)
                <a href="{{ route('admin.players.report', array_merge(request()->except([$key, 'page']))) }}">{{ $label }} <i class="fa-solid fa-xmark"></i></a>
            @endforeach
        </div>
    @endif

    <div class="admin-table-wrap player-report-scroll">
        <table class="admin-table player-report-table numeric-table">
            <thead><tr>
                @foreach([
                    'name' => 'Member', 'deposit_total' => 'Deposits', 'withdrawal_total' => 'Withdrawals',
                    'net_deposit' => 'Net cash in', 'bet_count' => 'Bets', 'stake_total' => 'Staked',
                    'payout_total' => 'Won', 'player_net' => 'Player W/L', 'house_ggr' => 'House GGR',
                    'balance' => 'Balance', 'last_bet_at' => 'Last bet',
                ] as $field => $label)
                    <th class="{{ $filters['sort'] === $field ? 'is-sorted' : '' }}"><a href="{{ $sortUrl($field) }}">{{ $label }} <i class="fa-solid {{ $sortIcon($field) }}"></i></a></th>
                @endforeach
                <th>RTP</th>
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($players as $player)
                @php $metrics = Report::metrics($player); @endphp
                <tr>
                    <td class="player-report-member">
                        <span class="player-report-avatar">{{ strtoupper(substr($player->name ?: 'P', 0, 1)) }}</span>
                        <div>
                            <a href="{{ route('admin.members.show', $player) }}"><strong>{{ $player->name }}</strong></a>
                            <span class="cell-subtitle">{{ $player->phone ?: 'No phone' }}{{ $player->active ? '' : ' · disabled' }}</span>
                        </div>
                    </td>
                    <td>{{ $money($metrics['deposit_total']) }}<span class="cell-subtitle">{{ number_format($metrics['deposit_count']) }}x</span></td>
                    <td>{{ $money($metrics['withdrawal_total']) }}<span class="cell-subtitle">{{ number_format($metrics['withdrawal_count']) }}x</span></td>
                    <td class="{{ $tone($metrics['net_deposit']) }}"><strong>{{ $signed($metrics['net_deposit']) }}</strong></td>
                    <td>{{ number_format($metrics['bet_count']) }}</td>
                    <td>{{ $money($metrics['stake_total']) }}</td>
                    <td>{{ $money($metrics['payout_total']) }}</td>
                    <td class="{{ $tone($metrics['player_net']) }}"><strong>{{ $signed($metrics['player_net']) }}</strong></td>
                    <td class="{{ $tone($metrics['house_ggr']) }}"><strong>{{ $signed($metrics['house_ggr']) }}</strong></td>
                    <td>{{ $money($player->credits) }}<span class="cell-subtitle">{{ $money($player->withdrawable_balance) }} free</span></td>
                    <td>{{ $metrics['last_bet_at']?->format('M j, H:i') ?: '—' }}</td>
                    <td>
                        @if($metrics['rtp'] === null)
                            <span class="cell-subtitle">—</span>
                        @else
                            <div class="player-rtp" title="Return to player {{ number_format($metrics['rtp'], 1) }}%">
                                <i><em style="width: {{ min(100, round($metrics['rtp'], 1)) }}%"></em></i>
                                <b>{{ number_format($metrics['rtp'], 1) }}%</b>
                            </div>
                        @endif
                    </td>
                    <td><a class="player-report-link" href="{{ route('admin.members.show', [$player, 'tab' => 'gaming']) }}">Open <i class="fa-solid fa-arrow-right"></i></a></td>
                </tr>
            @empty
                <tr><td colspan="13"><div class="admin-empty"><strong>No members match these filters.</strong><span>Try a wider period or clear the segment filter.</span></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('admin.casino._pagination', ['paginator' => $players])
</section>
@endsection
