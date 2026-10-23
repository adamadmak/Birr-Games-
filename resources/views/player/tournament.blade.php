@extends('layouts.app')

@section('title', 'Daily Tournament - Birr Games')

@section('content')
@php
    $money = fn ($amount) => \App\Support\Money::format($amount, 0);
    $topThree = $board->take(3)->keyBy('rank');
    $rest = $board->slice(3);
    $myRow = $me ? $board->firstWhere('user_id', $me->id) : null;
    $leaderStake = max(1.0, (float) ($board->first()['staked'] ?? 1));
    $initial = fn ($name) => mb_strtoupper(mb_substr((string) $name, 0, 1)) ?: '?';
    // The podium is drawn 2 - 1 - 3 so the winner stands in the middle and tallest.
    $podiumOrder = [2, 1, 3];
    $medal = [1 => 'gold', 2 => 'silver', 3 => 'bronze'];
    $ordinal = [1 => '1st', 2 => '2nd', 3 => '3rd'];
@endphp

<div class="player-account-page tn-page">

    {{-- ===== Hero ===== --}}
    <section class="tn-hero">
        <div class="tn-hero-glow" aria-hidden="true"></div>
        <svg class="tn-hero-trophy" width="200" height="220" viewBox="0 0 200 220" aria-hidden="true" fill="none">
            <defs>
                <linearGradient id="tnGold" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffe27a"/><stop offset=".55" stop-color="#ffc72c"/><stop offset="1" stop-color="#d9a410"/></linearGradient>
                <linearGradient id="tnGreen" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3be9a4"/><stop offset="1" stop-color="#0a8f5b"/></linearGradient>
            </defs>
            <path d="M40 30h120c0 56-26 96-60 108C66 126 40 86 40 30Z" fill="url(#tnGold)"/>
            <path d="M40 44c-34 0-34 52 22 62M160 44c34 0 34 52-22 62" stroke="#d9a410" stroke-width="12" stroke-linecap="round"/>
            <path d="M62 30h26c0 50 8 82 30 104-40-10-56-48-56-104Z" fill="#fff" fill-opacity=".3"/>
            <rect x="88" y="136" width="24" height="34" fill="#d9a410"/>
            <rect x="56" y="168" width="88" height="24" rx="8" fill="url(#tnGreen)"/>
            <rect x="70" y="192" width="60" height="12" rx="5" fill="#0a8f5b"/>
            <path d="M100 52l9 20 22 2-16 15 5 22-20-11-20 11 5-22-16-15 22-2Z" fill="#fff" fill-opacity=".92"/>
        </svg>

        <div class="tn-hero-main">
            <span class="tn-live">{!! $isToday ? '<i></i> Live now' : 'Final board' !!}</span>
            <h1>Daily Tournament</h1>
            <p>Stake more than everyone else today and climb to the top. The board resets every midnight.</p>
        </div>

        <div class="tn-hero-stats">
            <div class="tn-pool">
                <small>Prize pool</small>
                <strong>{{ $prizePool > 0 ? $money($prizePool) : 'Play to climb' }}</strong>
                <span>Top {{ number_format($paidPlaces) }} players are paid</span>
            </div>

            @if($isToday)
                <div class="tn-clock" data-tournament-countdown data-resets-at="{{ $resetsAt->toIso8601String() }}">
                    <small>Resets in</small>
                    <div>
                        <span><b data-countdown-hours>--</b><i>Hours</i></span>
                        <span><b data-countdown-minutes>--</b><i>Min</i></span>
                        <span><b data-countdown-seconds>--</b><i>Sec</i></span>
                    </div>
                </div>
            @else
                <div class="tn-clock is-closed">
                    <small>Board closed</small>
                    <div><span><b>{{ $day->format('M j') }}</b><i>{{ $day->format('l') }}</i></span></div>
                </div>
            @endif
        </div>
    </section>

    {{-- ===== Day picker ===== --}}
    <nav class="tn-days" aria-label="Tournament day">
        @foreach($days as $tab)
            <a class="{{ $offset === $tab['offset'] ? 'active' : '' }}" href="{{ route('player.tournament', ['day' => $tab['offset']]) }}">
                <strong>{{ $tab['label'] }}</strong>
                <small>{{ $tab['date']->format('M j') }}</small>
            </a>
        @endforeach
    </nav>

    {{-- ===== Podium: the real top three, or the prizes waiting for whoever takes them ===== --}}
    <section class="tn-podium {{ $board->isEmpty() ? 'is-open' : '' }}" aria-label="{{ $board->isEmpty() ? 'Prizes up for grabs' : 'Top three' }}">
        @foreach($podiumOrder as $place)
            @php($row = $topThree->get($place))
            <article class="tn-pod tn-pod-{{ $medal[$place] }} place-{{ $place }} {{ $row ? '' : 'is-empty' }} {{ $row && $myRow && $row['user_id'] === $myRow['user_id'] ? 'is-me' : '' }}">
                <div class="tn-pod-medal"><span>{{ $place }}</span></div>
                <div class="tn-pod-avatar">{{ $row ? $initial($row['name']) : '?' }}</div>
                <strong class="tn-pod-name">{{ $row ? $row['name'] : 'Open spot' }}</strong>
                <small class="tn-pod-sub">{{ $row ? ($row['phone'] ?: 'Player') : ($isToday ? 'Be the one' : 'Nobody took it') }}</small>
                <div class="tn-pod-prize">
                    <small>{{ $ordinal[$place] }} prize</small>
                    <b>{{ $money($prizes[$place]) }}</b>
                </div>
                @if($row)
                    <div class="tn-pod-stake">{{ number_format($row['staked']) }} <i>staked</i></div>
                @endif
            </article>
        @endforeach
    </section>

    @if($board->isEmpty())
        <section class="tn-empty">
            <div>
                <h2>{{ $isToday ? 'Nobody has played yet today' : 'Nobody played on this day' }}</h2>
                <p>{{ $isToday ? 'The board is empty, so every prize above is still up for grabs. Place a bet and your name goes straight to the top.' : 'There is no board for this day.' }}</p>
            </div>
            @if($isToday)<a class="button button-primary" href="{{ route('home') }}">Start playing</a>@endif
        </section>
    @endif

    {{-- ===== Everyone else ===== --}}
    @if($rest->isNotEmpty())
        <section class="tn-board" aria-label="Leaderboard">
            <header><h2>The chasing pack</h2><span>Ranked by total stake</span></header>
            @foreach($rest as $row)
                <article class="tn-row {{ $myRow && $row['user_id'] === $myRow['user_id'] ? 'is-me' : '' }}">
                    <span class="tn-row-rank">{{ $row['rank'] }}</span>
                    <span class="tn-row-avatar">{{ $initial($row['name']) }}</span>
                    <div class="tn-row-player">
                        <strong>{{ $row['name'] }}@if($myRow && $row['user_id'] === $myRow['user_id']) <em>You</em>@endif</strong>
                        <small>{{ $row['phone'] ?: 'Player' }}</small>
                    </div>
                    <div class="tn-row-bar" aria-hidden="true">
                        <progress max="{{ $leaderStake }}" value="{{ $row['staked'] }}"></progress>
                    </div>
                    <span class="tn-row-stake">{{ number_format($row['staked']) }}<i>staked</i></span>
                    <span class="tn-row-prize {{ $row['prize'] > 0 ? 'is-paid' : '' }}">{{ $row['prize'] > 0 ? $money($row['prize']) : '—' }}</span>
                </article>
            @endforeach
        </section>
    @endif

    {{-- ===== Where the viewer stands ===== --}}
    @auth
        <section class="tn-you">
            @if($myRow)
                <div class="tn-you-rank"><small>Your place</small><strong>#{{ $myRow['rank'] }}</strong></div>
                <div><small>Staked {{ $isToday ? 'today' : 'that day' }}</small><strong>{{ number_format($myRow['staked']) }}</strong></div>
                <div><small>Prize if the board holds</small><strong>{{ $myRow['prize'] > 0 ? $money($myRow['prize']) : '—' }}</strong></div>
            @else
                <div class="tn-you-empty">
                    <strong>You are not on {{ $isToday ? "today's" : 'this' }} board yet</strong>
                    <span>Top {{ number_format($paidPlaces) }} players by daily stake share the prize pool.</span>
                </div>
                @if($isToday)<a class="button button-primary" href="{{ route('home') }}">Play now</a>@endif
            @endif
        </section>
    @endauth

    {{-- ===== How it works ===== --}}
    <section class="tn-how">
        <article><span>1</span><div><strong>Play any game</strong><p>Every bet you place today counts toward your total stake.</p></div></article>
        <article><span>2</span><div><strong>Climb the board</strong><p>The highest total stake takes first place. Ties go to whoever got there first.</p></div></article>
        <article><span>3</span><div><strong>Get paid</strong><p>Prizes are paid by the Birr Games team after the board closes at midnight.</p></div></article>
    </section>
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
