@extends('layouts.app')

@section('title', 'Daily Tournament - Birr Games')

@section('content')
@php
    $money = fn ($amount) => \App\Support\Money::format($amount, 0);
    $topThree = $board->take(3);
    $rest = $board->slice(3);
    $myRow = $me ? $board->firstWhere('user_id', $me->id) : null;
@endphp

<div class="player-account-page tournament-page">
    <section class="tournament-hero">
        <div class="tournament-hero-badge"><svg class="t-ico" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.9 16.1C1 12.2 1 5.8 4.9 1.9"/><path d="M7.8 13.2a5.9 5.9 0 0 1 0-8.4"/><circle cx="12" cy="9" r="2"/><path d="M16.2 4.8a5.9 5.9 0 0 1 0 8.4"/><path d="M19.1 1.9a9.96 9.96 0 0 1 0 14.2"/><path d="M12 11v11"/></svg></div>
        <div class="tournament-hero-copy">
            <h1>Daily Tournament</h1>
            <p>Top players by stake &middot; {{ $prizePool > 0 ? $money($prizePool).' prize pool' : 'Play to climb the board' }}</p>
        </div>
        @if($isToday)
            <div class="tournament-countdown" data-tournament-countdown data-resets-at="{{ $resetsAt->toIso8601String() }}">
                <small>Resets in</small>
                <div>
                    <span><b data-countdown-hours>--</b><i>H</i></span>
                    <span><b data-countdown-minutes>--</b><i>M</i></span>
                    <span><b data-countdown-seconds>--</b><i>S</i></span>
                </div>
            </div>
        @else
            <div class="tournament-countdown is-closed">
                <small>Final board</small>
                <div><span><b>{{ $day->format('M j') }}</b></span></div>
            </div>
        @endif
    </section>

    <nav class="tournament-days" aria-label="Tournament day">
        @foreach($days as $tab)
            <a class="{{ $offset === $tab['offset'] ? 'active' : '' }}" href="{{ route('player.tournament', ['day' => $tab['offset']]) }}">{{ $tab['label'] }}</a>
        @endforeach
    </nav>

    @if($board->isEmpty())
        <section class="tournament-empty">
            <svg class="t-ico" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
            <strong>No stakes recorded {{ $isToday ? 'yet today' : 'on this day' }}</strong>
            <span>{{ $isToday ? 'Place a bet in the lobby and your name appears here.' : 'Nobody played on this day.' }}</span>
            <a class="button button-primary" href="{{ route('home') }}">Open the lobby</a>
        </section>
    @else
        <section class="tournament-podium">
            @foreach($topThree as $row)
                <article class="tournament-podium-card rank-{{ $row['rank'] }}">
                    <span class="tournament-rank-ghost">{{ $row['rank'] }}</span>
                    <span class="tournament-rank-chip">#{{ $row['rank'] }}</span>
                    <strong>{{ $row['name'] }}</strong>
                    <small>{{ $row['phone'] }}</small>
                    <div class="tournament-podium-stats">
                        <span><svg class="t-ico" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="m13.4 10.6 4.6-4.6"/><path d="M3.3 17a9 9 0 1 1 17.4 0"/></svg> {{ number_format($row['staked']) }}</span>
                        @if($row['prize'] > 0)<span class="tournament-prize"><svg class="t-ico" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="9" cy="6" rx="7" ry="3"/><path d="M2 6v5c0 1.66 3.13 3 7 3s7-1.34 7-3V6"/><path d="M2 11v5c0 1.66 3.13 3 7 3 1.2 0 2.33-.13 3.32-.36"/><path d="M15.5 12c3.6 0 6.5 1.34 6.5 3s-2.9 3-6.5 3S9 16.66 9 15"/><path d="M22 15v3c0 1.66-2.9 3-6.5 3S9 19.66 9 18v-3"/></svg> {{ $money($row['prize']) }}</span>@endif
                    </div>
                </article>
            @endforeach
        </section>

        @if($rest->isNotEmpty())
            <section class="tournament-board">
                @foreach($rest as $row)
                    <article class="tournament-row {{ $myRow && $row['user_id'] === $myRow['user_id'] ? 'is-me' : '' }}">
                        <span class="tournament-row-ghost">{{ str_pad((string) $row['rank'], 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="tournament-row-rank">{{ str_pad((string) $row['rank'], 2, '0', STR_PAD_LEFT) }}</span>
                        <div class="tournament-row-player">
                            <strong>{{ $row['name'] }}</strong>
                            <small>{{ $row['phone'] }}</small>
                        </div>
                        <span class="tournament-row-stat"><svg class="t-ico" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="m13.4 10.6 4.6-4.6"/><path d="M3.3 17a9 9 0 1 1 17.4 0"/></svg> {{ number_format($row['staked']) }}</span>
                        <span class="tournament-row-stat {{ $row['prize'] > 0 ? 'is-prize' : 'is-muted' }}">
                            @if($row['prize'] > 0)<svg class="t-ico" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="9" cy="6" rx="7" ry="3"/><path d="M2 6v5c0 1.66 3.13 3 7 3s7-1.34 7-3V6"/><path d="M2 11v5c0 1.66 3.13 3 7 3 1.2 0 2.33-.13 3.32-.36"/><path d="M15.5 12c3.6 0 6.5 1.34 6.5 3s-2.9 3-6.5 3S9 16.66 9 15"/><path d="M22 15v3c0 1.66-2.9 3-6.5 3S9 19.66 9 18v-3"/></svg> {{ $money($row['prize']) }}@else — @endif
                        </span>
                    </article>
                @endforeach
            </section>
        @endif
    @endif

    @auth
        <section class="tournament-you">
            @if($myRow)
                <div><small>Your position</small><strong>#{{ $myRow['rank'] }}</strong></div>
                <div><small>Staked {{ $isToday ? 'today' : 'that day' }}</small><strong>{{ number_format($myRow['staked']) }}</strong></div>
                <div><small>Prize if the board holds</small><strong>{{ $myRow['prize'] > 0 ? $money($myRow['prize']) : '—' }}</strong></div>
            @else
                <div class="tournament-you-empty">
                    <strong>You are not on {{ $isToday ? "today's" : 'this' }} board yet</strong>
                    <span>Top {{ number_format($paidPlaces) }} players by daily stake share the prize pool. Prizes are paid by the Birr Games team after the board closes.</span>
                </div>
            @endif
        </section>
    @endauth

    <p class="tournament-terms">
        Ranking is by total stake placed between midnight and midnight, taken from recorded game rounds.
        Equal stakes are ranked by who reached the amount first. The board resets every day at midnight.
    </p>
</div>

<script>
(function () {
    var box = document.querySelector('[data-tournament-countdown]');
    if (!box) return;

    var target = new Date(box.dataset.resetsAt).getTime();
    var hours = box.querySelector('[data-countdown-hours]');
    var minutes = box.querySelector('[data-countdown-minutes]');
    var seconds = box.querySelector('[data-countdown-seconds]');
    var pad = function (value) { return String(Math.max(0, value)).padStart(2, '0'); };

    function tick() {
        var remaining = Math.max(0, Math.floor((target - Date.now()) / 1000));
        hours.textContent = pad(Math.floor(remaining / 3600));
        minutes.textContent = pad(Math.floor((remaining % 3600) / 60));
        seconds.textContent = pad(remaining % 60);
        if (remaining === 0) window.setTimeout(function () { window.location.reload(); }, 2000);
    }

    tick();
    window.setInterval(tick, 1000);
})();
</script>
@endsection
