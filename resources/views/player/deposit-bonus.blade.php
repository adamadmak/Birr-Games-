@extends('layouts.app')

@section('title', __('Deposit Bonus').' - Vamoseone')

@section('content')
<div class="player-account-page bonus-page">
    <header class="player-page-heading">
        <p>{{ __('Vamoseone Rewards') }}</p>
        <h1>{{ __('Deposit Bonus') }}</h1>
        <span>{{ __('A one-time Ethiopian Birr welcome reward stored in your account.') }}</span>
    </header>

    <section class="bonus-hero-panel">
        <div class="bonus-orbit" aria-hidden="true"><span>+</span></div>
        <div class="bonus-copy">
            <span class="bonus-label">{{ __('One-time local reward') }}</span>
            <h2>{{ __('Power up your first session.') }}</h2>
            <p>{!! __('Claim <strong>:amount</strong> on top of your first deposit. It is play credit: use it on any game, keep what it wins.', ['amount' => e(\App\Support\Money::format($bonusCredits))]) !!}</p>
            @if($claim)
                <div class="bonus-claimed-state"><span>&#10003;</span><div><strong>{{ __('Bonus claimed') }}</strong><small>{{ __(':amount added :when', ['amount' => \App\Support\Money::format($claim->credits), 'when' => $claim->claimed_at->diffForHumans()]) }}</small></div></div>
            @elseif (! $hasDeposit)
                <a class="bonus-claim-button" href="{{ route('player.wallet', ['tab' => 'deposit']) }}">{{ __('Make your first deposit') }}</a>
                <p class="bonus-fineprint">{{ __('The bonus unlocks once an agent has confirmed a deposit on this account.') }}</p>
            @else
                <form method="POST" action="{{ route('deposit-bonus.claim') }}">@csrf<button class="bonus-claim-button" type="submit">{{ __('Claim :amount', ['amount' => \App\Support\Money::format($bonusCredits)]) }}</button></form>
            @endif
        </div>
        <div class="bonus-value-card"><small>{{ __('Your reward') }}</small><strong>+{{ number_format($bonusCredits) }}</strong><span>{{ __('ETHIOPIAN BIRR') }}</span></div>
    </section>

    <section class="bonus-how-it-works">
        <div class="wallet-section-title"><div><p>{{ __('Simple and transparent') }}</p><h2>{{ __('How it works') }}</h2></div><span>{{ __('Local only') }}</span></div>
        <div class="bonus-steps">
            <article><span>1</span><div><h3>{{ __('Sign in') }}</h3><p>{{ __('The offer is attached to your local Vamoseone account.') }}</p></div></article>
            <article><span>2</span><div><h3>{{ __('Claim once') }}</h3><p>{{ __('One claim is allowed per player and stored in your bonus history.') }}</p></div></article>
            <article><span>3</span><div><h3>{{ __('Play agents') }}</h3><p>{{ __('The Birr balance can be used with Lucky Dice, Coin Flip, and High / Low.') }}</p></div></article>
        </div>
    </section>
</div>
@endsection
