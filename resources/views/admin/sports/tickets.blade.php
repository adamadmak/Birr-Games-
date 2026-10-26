@extends('layouts.admin')
@section('title', 'Bet Tickets')
@section('content')
@php
    $pageUrl = fn (int $p) => route('admin.sports.tickets', array_filter(array_merge(request()->except('page'), ['page' => $p]), fn ($v) => $v !== null && $v !== ''));
    $statusClass = fn (string $s) => match ($s) {
        'won', 'win', 'paid', 'cashout', 'cashed_out' => 'won',
        'lost', 'lose', 'loss' => 'lost',
        'void', 'cancelled', 'canceled', 'refunded' => 'void',
        default => 'open',
    };
@endphp

<div class="admin-page-heading casino-page-heading">
    <div>
        <h1>Bet Tickets</h1>
        <p>Every sportsbook ticket from WinHouse, with a receipt you can print at the counter.</p>
    </div>
</div>

@unless($result['ok'])
    <div class="configuration-form-feedback sports-ticket-notice">
        <strong>WinHouse tickets are not available right now.</strong>
        <span>{{ $result['error'] }} Showing the wallet's own record of stakes and payouts instead; those receipts carry no selections. Printing from WinHouse's agent panel (Tickets → All bet tickets → Print) still works.</span>
    </div>
@endunless

<form class="casino-transaction-filters" method="GET">
    <label class="casino-search-box"><i class="fa-solid fa-ticket"></i><input type="search" name="ticket" value="{{ $ticketSearch }}" placeholder="Ticket number..."></label>
    <label class="casino-search-box"><i class="fa-solid fa-phone"></i><input type="search" name="phone" value="{{ $phone }}" placeholder="Player phone or name..."></label>
    <details class="casino-filter-menu"><summary><i class="fa-regular fa-calendar"></i> {{ $date ? $date->format('d M Y') : 'Date' }}</summary><div><label>Placed on<input type="date" name="date" value="{{ $date?->toDateString() }}"></label></div></details>
    <details class="casino-filter-menu"><summary><i class="fa-regular fa-circle-plus"></i> {{ $status !== '' ? ucfirst($status) : 'Status' }}</summary><div><label>Status<select name="status"><option value="">All statuses</option>@foreach(['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost', 'void' => 'Void', 'cashout' => 'Cash out'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></label></div></details>
    <input type="hidden" name="per_page" value="{{ $perPage }}">
    <button class="casino-filter-submit" type="submit">Search</button>
    @if(request()->except(['page', 'per_page']))<a class="casino-filter-reset" href="{{ route('admin.sports.tickets') }}">Reset</a>@endif
</form>

<section class="casino-table-card">
    <div class="casino-table-scroll">
        <table class="casino-data-table transaction-table sports-ticket-table">
            <thead><tr>
                <th>Ticket</th><th>Player</th><th>Placed</th><th>Selections</th><th>Odds</th><th>Stake</th><th>Possible win</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($tickets as $ticket)
                @php
                    $user = $ticket['player_id'] ? $users->get($ticket['player_id']) : null;
                    $wallet = $local->get($ticket['id']);
                    $stake = $ticket['stake'] > 0 ? $ticket['stake'] : ($wallet->stake ?? 0);
                    $paid = $ticket['paid'] > 0 ? $ticket['paid'] : ($wallet->paid ?? 0);
                    $shown = $ticket['status'] !== 'open' ? $ticket['status'] : ($wallet->status ?? 'open');
                @endphp
                <tr>
                    <td><code class="sports-ticket-code">{{ $ticket['id'] }}</code>@if($ticket['type'] !== '')<small>{{ ucfirst($ticket['type']) }}</small>@endif</td>
                    <td>
                        @if($user)
                            <a class="casino-phone-link" href="{{ route('admin.members.show', $user->id) }}">{{ $user->name }}</a><small>{{ $user->phone ?: '—' }}</small>
                        @elseif($ticket['player'] !== '')
                            <span>{{ $ticket['player'] }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $ticket['placed_at']?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td>
                        @if(count($ticket['selections']))
                            <details class="sports-ticket-selections">
                                <summary>{{ count($ticket['selections']) }} {{ \Illuminate\Support\Str::plural('pick', count($ticket['selections'])) }}</summary>
                                <ul>
                                    @foreach($ticket['selections'] as $pick)
                                        <li>
                                            <strong>{{ $pick['event'] ?: '—' }}@if($pick['live']) <i class="sports-ticket-live">Live</i>@endif</strong>
                                            <span>{{ $pick['market'] }}{{ $pick['market'] !== '' && $pick['pick_label'] !== '' ? ': ' : '' }}{{ $pick['pick_label'] }}</span>
                                            <em>@if($pick['odds'] > 0)@ {{ number_format($pick['odds'], 2) }}@endif @if($pick['score'] !== '') · {{ $pick['score'] }}@endif @if($pick['status'] !== '') · {{ ucfirst($pick['status']) }}@endif</em>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @else
                            <span class="sports-ticket-muted">—</span>
                        @endif
                    </td>
                    <td>{{ $ticket['odds'] > 0 ? number_format($ticket['odds'], 2) : '—' }}</td>
                    <td>{{ \App\Support\Money::format($stake, 2) }}</td>
                    <td>
                        {{ $ticket['possible_win'] > 0 ? \App\Support\Money::format($ticket['possible_win'], 2) : '—' }}
                        @if($paid > 0)<small>Paid {{ \App\Support\Money::format($paid, 2) }}</small>@endif
                    </td>
                    <td><span class="sports-ticket-status {{ $statusClass($shown) }}">{{ ucfirst(str_replace('_', ' ', $shown)) }}</span></td>
                    <td class="sports-ticket-actions">
                        <a class="casino-page-button" href="{{ route('admin.sports.tickets.print', ['ticket' => $ticket['id'], 'auto' => 1]) }}" target="_blank" rel="noopener" title="Print receipt"><i class="fa-solid fa-print"></i></a>
                        <a class="casino-page-button" href="{{ route('admin.sports.tickets.print', ['ticket' => $ticket['id']]) }}" target="_blank" rel="noopener" title="Open receipt"><i class="fa-regular fa-eye"></i></a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="casino-empty"><i class="fa-solid fa-ticket"></i><strong>No tickets found</strong><span>{{ $result['ok'] ? 'WinHouse returned no tickets for these filters.' : 'No sportsbook stakes have reached the wallet yet.' }}</span></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <footer class="casino-pagination">
        <span>
            @if($result['ok'])
                {{ number_format($total) }} ticket(s){{ $sifted ? ' matching, among the latest '.number_format(\App\Services\WinhouseTicketsClient::SCAN_LIMIT).' at WinHouse' : ' at WinHouse' }}
            @else
                {{ number_format($total) }} ticket(s) in the wallet
            @endif
        </span>
        <div>
            <form method="GET">
                @foreach(request()->except(['per_page', 'page']) as $key => $value)
                    @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                @endforeach
                <label>Page Size <select name="per_page" onchange="this.form.submit()">@foreach([10, 25, 50] as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>@endforeach</select></label>
            </form>
            @if($page <= 1)<span class="casino-page-button disabled"><i class="fa-solid fa-chevron-left"></i></span>@else<a class="casino-page-button" href="{{ $pageUrl($page - 1) }}"><i class="fa-solid fa-chevron-left"></i></a>@endif
            <strong>Page {{ $page }} of {{ $lastPage }}</strong>
            @if($page < $lastPage)<a class="casino-page-button" href="{{ $pageUrl($page + 1) }}"><i class="fa-solid fa-chevron-right"></i></a>@else<span class="casino-page-button disabled"><i class="fa-solid fa-chevron-right"></i></span>@endif
        </div>
    </footer>
</section>

<p class="sports-ticket-footnote">Tickets are read from <code>{{ $apiBase }}/tenant/api/tickets</code> with the WinHouse keys. The same tickets can be printed from WinHouse's agent panel under Tickets → All bet tickets.</p>
@endsection
