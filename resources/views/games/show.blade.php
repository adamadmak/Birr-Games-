@extends('layouts.app')

@section('title', $game->title.' — Birr Games')

@section('content')
<div class="game-page">
    <a class="back-link" href="{{ route('home') }}">← Back to lobby</a>

    @if ($game->launchesViaProvider())
        <div class="mobile-game-closebar"><a href="{{ route('home') }}"><span>&times;</span> Close</a></div>
        <div class="provider-preview provider-preview-modal glass-panel">
            <div class="provider-preview-art art-{{ $game->art }} {{ $game->image_source ? 'has-image' : '' }}">
                @if($game->image_source)<img class="game-cover" src="{{ $game->image_source }}" alt="{{ $game->title }}">@endif
                <span class="game-symbol">{{ $game->symbol }}</span>
            </div>
            <div class="provider-preview-copy">
                <p class="eyebrow">{{ $game->provider->name }} · {{ $game->providerLabel() }}</p>
                <div class="provider-preview-title"><h1>{{ $game->title }}</h1><button type="button" class="game-favorite" data-game-favorite="{{ $game->slug }}" aria-pressed="false"><span>Add to favorite</span><i>♡</i></button></div>
                <p>{{ $game->description }}</p>
                @if ($errors->has('provider'))<div class="form-errors">{{ $errors->first('provider') }}</div>@endif
                <div class="provider-preview-actions">
                    @auth
                        <form method="POST" action="{{ route('games.provider.launch', $game) }}">@csrf<button class="button button-primary" type="submit">Play Now</button></form>
                    @else
                        <a class="button button-primary" href="{{ route('login') }}">Log in to play</a>
                        <a class="button button-soft" href="{{ route('register') }}">Create account</a>
                    @endauth
                </div>
            </div>
        </div>
    @elseif ($game->playable && $agent)
        <div class="dice-layout agent-layout">
            <section class="dice-stage agent-stage art-{{ $game->art }}">
                <div class="stage-topline"><span>{{ $game->provider->name }}</span><span>Local game agent · auditable play</span></div>
                <div class="result-orbit {{ session('round.won') ? 'winner' : '' }}">
                    <span class="result-die agent-result">{{ session('round.symbol', $game->symbol) }}</span>
                </div>
                @if (session('round'))
                    <p class="round-kicker">{{ session('round.message') }}</p>
                    <h1>{{ session('round.headline') }}</h1>
                    <p>{{ session('round.won') ? 'Payout: '.\App\Support\Money::format(session('round.payout')).'.' : 'Choose again when you are ready.' }}</p>
                @else
                    <p class="round-kicker">Real local game agent</p>
                    <h1>{{ $game->title }}</h1>
                    <p>{{ $game->description }}</p>
                @endif
            </section>

            <section class="bet-panel glass-panel">
                @auth
                    <div class="panel-balance"><span>Available balance</span><strong>{{ \App\Support\Money::format(auth()->user()->fresh()->credits) }}</strong></div>
                    @if ($errors->any())<div class="form-errors">{{ $errors->first() }}</div>@endif
                    <form method="POST" action="{{ route('games.play', $game) }}" class="dice-form">
                        @csrf
                        <fieldset>
                            <legend>Make your call</legend>
                            <div class="number-picks agent-choice-grid choices-{{ count($choices) }}">
                                @foreach($choices as $choice)
                                    <label title="{{ $choice['label'] }}"><input type="radio" name="choice" value="{{ $choice['value'] }}" {{ old('choice', $choices[0]['value']) === $choice['value'] ? 'checked' : '' }}><span>{{ $choice['symbol'] }}<small class="unit">{{ $choice['label'] }}</small></span></label>
                                @endforeach
                            </div>
                        </fieldset>
                        <label class="stake-input">Stake (ETB)<input type="number" name="stake" min="10" max="1000" step="10" value="{{ old('stake', 100) }}" required></label>
                        <div class="quick-stakes">@foreach ([50,100,250,500] as $stake)<button type="button" data-stake="{{ $stake }}">{{ \App\Support\Money::format($stake) }}</button>@endforeach</div>
                        <button class="button button-primary button-wide roll-button" type="submit">Run {{ $game->title }} agent</button>
                        <p class="game-rule">Local random outcomes using an ETB-denominated play balance.</p>
                    </form>
                @else
                    <h2>Ready to play {{ $game->title }}?</h2>
                    <p>Create a local account and receive {{ \App\Support\Money::format($welcomeBalance) }}.</p>
                    <a class="button button-primary button-wide" href="{{ route('register') }}">Create account</a>
                    <a class="button button-soft button-wide" href="{{ route('login') }}">Log in</a>
                @endauth
            </section>
        </div>

        @auth
            <section class="round-history glass-panel">
                <div class="section-heading"><div><p>Your activity</p><h2>Recent {{ $game->title }} rounds</h2></div></div>
                <div class="history-table">
                    <div class="history-row history-head"><span>Outcome</span><span>Stake</span><span>Payout</span><span>Time</span></div>
                    @forelse ($recentRounds as $round)
                        <div class="history-row"><span>{{ $round->result['message'] ?? $round->result['outcome'] ?? 'Completed' }}</span><span>{{ \App\Support\Money::format($round->stake) }}</span><strong>{{ \App\Support\Money::format($round->payout) }}</strong><small>{{ $round->created_at->diffForHumans() }}</small></div>
                    @empty<p class="empty-history">Your first round will appear here.</p>@endforelse
                </div>
            </section>
        @endauth
    @else
        <section class="coming-soon glass-panel">
            <div class="game-art art-{{ $game->art }} large-art {{ $game->image_source ? 'has-image' : '' }}">@if($game->image_source)<img class="game-cover" src="{{ $game->image_source }}" alt="{{ $game->title }}">@endif<span class="game-tag">{{ $game->tag }}</span><span class="game-symbol">{{ $game->symbol }}</span><span class="art-glow"></span></div>
            <p class="eyebrow">{{ $game->provider->name }}</p><h1>{{ $game->title }}</h1><p>{{ $game->description }} This provider concept is visible in the live lobby, but needs an official integration or a local agent before it can accept rounds.</p>
            <a class="button button-primary" href="{{ route('home') }}">Browse integrated games</a>
        </section>
    @endif
</div>
@endsection
