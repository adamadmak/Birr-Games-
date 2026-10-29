@extends('layouts.app')

@section('title', __('My Bets').' - Vamoseone')

@section('content')
@php
    $badge = fn (string $status) => match ($status) {
        'won' => ['won', __('Won')],
        'lost' => ['lost', __('Lost')],
        'open', 'pending' => ['open', __('Open')],
        'cashed_out', 'cashout' => ['open', __('Cashed out')],
        'void', 'cancelled', 'rejected', 'refunded' => ['void', __('Void')],
        default => ['open', ucfirst($status)],
    };
@endphp
<div class="player-account-page bets-page">
    <header class="player-page-heading">
        <p>{{ __('Sportsbook') }}</p>
        <h1>{{ __('My Bets') }}</h1>
        <span>{{ __('Your bet slips, with each pick marked when its match ends.') }}</span>
    </header>

    <nav class="bets-tabs" aria-label="{{ __('Filter bets') }}">
        @foreach (['all' => __('All'), 'open' => __('Open'), 'won' => __('Won'), 'lost' => __('Lost')] as $key => $label)
            <a class="{{ $tab === $key ? 'active' : '' }}" href="{{ route('player.bets', $key === 'all' ? [] : ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @if ($unavailable)
        <p class="bets-empty">{{ __('Your bets cannot be loaded right now. Try again in a minute.') }}</p>
    @elseif ($tickets->isEmpty())
        <div class="bets-empty">
            <p>{{ $tab === 'all' ? __('You have no bets yet.') : __('No bets here.') }}</p>
            <a class="button button-primary" href="{{ route('sportsbook') }}">{{ __('Go to sports') }}</a>
        </div>
    @else
        <div class="bets-list">
            @foreach ($tickets as $ticket)
                @php([$tone, $label] = $badge($ticket['status']))
                <article class="bet-slip is-{{ $tone }}">
                    <header>
                        <div>
                            <strong>{{ $ticket['type'] !== '' ? ucfirst($ticket['type']) : __('Bet') }}{{ count($ticket['selections']) > 1 ? ' · '.count($ticket['selections']).' '.__('picks') : '' }}</strong>
                            <small>#{{ $ticket['id'] }} · {{ $ticket['placed_at']?->format('M j, H:i') }}</small>
                        </div>
                        <span class="bet-status is-{{ $tone }}">{{ $label }}</span>
                    </header>
                    <ul class="bet-picks">
                        @foreach ($ticket['selections'] as $pick)
                            @php($result = $ticket['pick_results'][$loop->index] ?? null)
                            <li class="is-{{ $result ?? 'pending' }}">
                                <span class="bet-mark" aria-label="{{ match ($result) { 'won' => __('Won'), 'lost' => __('Lost'), 'void' => __('Void'), default => __('Not settled yet') } }}">{!! match ($result) { 'won' => '&#10003;', 'lost' => '&#10007;', 'void' => '&ndash;', default => '&middot;' } !!}</span>
                                <div>
                                    <b>{{ $pick['event'] ?: __('Selection').' '.$loop->iteration }}</b>
                                    <small>{{ $pick['market'] }}{{ $pick['market'] !== '' && $pick['pick_label'] !== '' ? ': ' : '' }}{{ $pick['pick_label'] }}@if($pick['score'] !== '') · {{ $pick['score'] }}@endif</small>
                                </div>
                                @if ($pick['odds'] > 0)<em>{{ number_format($pick['odds'], 2) }}</em>@endif
                            </li>
                        @endforeach
                    </ul>
                    <footer>
                        <span><small>{{ __('Stake') }}</small>{{ \App\Support\Money::exact($ticket['stake']) }}</span>
                        @if ($ticket['odds'] > 0)<span><small>{{ __('Odds') }}</small>{{ number_format($ticket['odds'], 2) }}</span>@endif
                        @if ($ticket['status'] === 'won' && $ticket['paid'] > 0)
                            <span class="bet-win"><small>{{ __('Won') }}</small>{{ \App\Support\Money::exact($ticket['paid']) }}</span>
                        @else
                            <span><small>{{ __('Possible win') }}</small>{{ \App\Support\Money::exact($ticket['possible_win']) }}</span>
                        @endif
                    </footer>
                </article>
            @endforeach
        </div>

        <nav class="bets-pager" aria-label="{{ __('More bets') }}">
            @if ($page > 1)<a href="{{ route('player.bets', array_filter(['tab' => $tab === 'all' ? null : $tab, 'page' => $page - 1])) }}">&larr; {{ __('Newer') }}</a>@endif
            @if ($hasMore)<a href="{{ route('player.bets', array_filter(['tab' => $tab === 'all' ? null : $tab, 'page' => $page + 1])) }}">{{ __('Older') }} &rarr;</a>@endif
        </nav>
    @endif
</div>
@endsection
