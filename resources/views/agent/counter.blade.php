@extends('layouts.agent')
@section('title', 'Counter')
@section('content')
<div class="agent-page-head">
    <div><p>Walk-in customers</p><h1>Counter</h1><span>Bet for customers without an account: they pay you cash, you print the ticket.</span></div>
</div>

<section class="agent-panel agent-booking">
    <div class="agent-panel-head"><div><h2>Booking code</h2><p>The customer built a slip on their phone and brought the 7-character code.</p></div></div>
    <form class="agent-booking-search" method="GET" action="{{ route('agent.counter') }}">
        <input type="text" name="code" value="{{ $typedCode }}" maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="K7M4QZT" aria-label="Booking code" required>
        <button class="agent-button teal" type="submit">Find slip</button>
    </form>

    @if($lookup)
        @if($lookup['error'])
            <p class="agent-alert error agent-booking-message">{{ $lookup['error'] }}</p>
        @endif
        @if($b = $lookup['booking'])
            <div class="agent-booking-summary">
                <span><small>Code</small><strong>{{ $b['code'] }}</strong></span>
                <span><small>Stake</small><strong>{{ \App\Support\Money::exact($b['stake']) }}</strong></span>
                <span><small>Type</small><strong>{{ ucfirst($b['mode'] ?: 'single') }}{{ $b['mode'] === 'system' && $b['system_size'] ? ' '.$b['system_size'] : '' }}</strong></span>
                <span><small>Still open</small><strong>{{ $b['available_count'] }} of {{ count($b['selections']) }}</strong></span>
                @if($b['expires_at'])<span><small>Expires</small><strong>{{ $b['expires_at']->format('M j, H:i') }}</strong></span>@endif
            </div>
            <div class="agent-table-wrap">
                <table class="agent-table agent-booking-table">
                    <thead><tr><th>Match</th><th>Kickoff</th><th>Pick</th><th>Odds</th><th></th></tr></thead>
                    <tbody>
                    @foreach($b['selections'] as $s)
                        <tr class="{{ $s['available'] ? '' : 'is-dropped' }}">
                            <td><strong>{{ $s['match'] }}</strong>@if($s['league'])<br><small>{{ $s['league'] }}</small>@endif</td>
                            <td>{{ $s['kickoff']?->format('M j, H:i') ?? '—' }}</td>
                            <td>{{ $s['market'] }}: {{ $s['outcome'] }}</td>
                            <td>
                                @if($s['current_odds'] !== null && $s['odds_at_booking'] !== null && abs($s['current_odds'] - $s['odds_at_booking']) > 0.001)
                                    <s>{{ number_format($s['odds_at_booking'], 2) }}</s> {{ number_format($s['current_odds'], 2) }}
                                @else
                                    {{ $s['current_odds'] !== null ? number_format($s['current_odds'], 2) : ($s['odds_at_booking'] !== null ? number_format($s['odds_at_booking'], 2) : '—') }}
                                @endif
                            </td>
                            <td>@if($s['available'])<span class="agent-badge success">Open</span>@else<span class="agent-badge rejected">Dropped{{ $s['reason'] ? ': '.$s['reason'] : '' }}</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($lookup['ok'] && $b['available_count'] > 0)
                <div class="agent-booking-actions">
                    <form method="POST" action="{{ route('agent.counter.open') }}" target="_blank">
                        @csrf
                        <input type="hidden" name="booking" value="{{ $b['code'] }}">
                        <button class="agent-button teal" type="submit">Open this slip as the counter</button>
                    </form>
                    <p>The slip opens in the sportsbook at current odds. Change the stake, add or remove picks there as the customer asks, take the cash, place it, then print it from Bet Tickets.</p>
                </div>
            @endif
        @endif
    @endif
</section>

<section class="agent-panel">
    <div class="agent-panel-head"><div><h2>How it works</h2><p>Your counter is a betting account only you can open, funded from your float.</p></div></div>
    <ol class="agent-counter-steps">
        <li>Move float to the counter below (once, or whenever it runs low).</li>
        <li>Take the customer's cash and open the sportsbook as the counter. Place exactly the bet they ask for. If they bring a booking code, find it above and open it instead.</li>
        <li>Print the ticket from Bet Tickets and hand it over.</li>
        <li>When a customer returns with a winning ticket, press Pay out on Bet Tickets and pay them the amount shown. Each ticket can be paid once.</li>
        <li>Move the counter balance back to your float whenever you like.</li>
    </ol>
</section>

<div class="agent-counter-grid">
    <section class="agent-panel">
        <div class="agent-panel-head"><div><h2>Balances</h2></div></div>
        <div class="agent-counter-balances">
            <div><small>Counter balance</small><strong>{{ \App\Support\Money::exact((float) $counter->credits) }}</strong></div>
            <div><small>Your float</small><strong>{{ \App\Support\Money::exact((float) $agent->balance) }}</strong>@if($floatMinimum > 0)<span>Minimum {{ \App\Support\Money::exact($floatMinimum) }}</span>@endif</div>
        </div>
        <form method="POST" action="{{ route('agent.counter.open') }}" target="_blank">
            @csrf
            <button class="agent-button teal" type="submit">Open the sportsbook as the counter</button>
        </form>
    </section>

    <section class="agent-panel">
        <div class="agent-panel-head"><div><h2>Move money</h2></div></div>
        <form class="agent-form" method="POST" action="{{ route('agent.counter.top-up') }}">
            @csrf
            <label>Float to counter<input type="number" name="amount" min="1" step="0.01" required placeholder="ETB"></label>
            <button class="agent-button teal" type="submit">Top up counter</button>
        </form>
        <form class="agent-form" method="POST" action="{{ route('agent.counter.give-back') }}">
            @csrf
            <label>Counter to float<input type="number" name="amount" min="0.01" step="0.01" max="{{ (float) $counter->credits }}" required placeholder="ETB"></label>
            <button class="agent-button" type="submit">Return to float</button>
        </form>
    </section>
</div>
@endsection
