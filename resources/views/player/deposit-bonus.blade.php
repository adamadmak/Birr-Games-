@extends('layouts.app')

@section('title', 'Deposit Bonus - Vamoseone')

@section('content')
<div class="player-account-page bonus-page">
    <header class="player-page-heading">
        <p>Vamoseone Rewards</p>
        <h1>Deposit Bonus</h1>
        <span>A one-time Ethiopian Birr welcome reward stored in your account.</span>
    </header>

    <section class="bonus-hero-panel">
        <div class="bonus-orbit" aria-hidden="true"><span>+</span></div>
        <div class="bonus-copy">
            <span class="bonus-label">One-time local reward</span>
            <h2>Power up your first session.</h2>
            <p>Claim <strong>{{ \App\Support\Money::format($bonusCredits) }}</strong> for exploring Vamoseone. No payment is required.</p>
            @if($claim)
                <div class="bonus-claimed-state"><span>&#10003;</span><div><strong>Bonus claimed</strong><small>{{ \App\Support\Money::format($claim->credits) }} added {{ $claim->claimed_at->diffForHumans() }}</small></div></div>
            @else
                <form method="POST" action="{{ route('deposit-bonus.claim') }}">@csrf<button class="bonus-claim-button" type="submit">Claim {{ \App\Support\Money::format($bonusCredits) }}</button></form>
            @endif
        </div>
        <div class="bonus-value-card"><small>Your reward</small><strong>+{{ number_format($bonusCredits) }}</strong><span>ETHIOPIAN BIRR</span></div>
    </section>

    <section class="bonus-how-it-works">
        <div class="wallet-section-title"><div><p>Simple and transparent</p><h2>How it works</h2></div><span>Local only</span></div>
        <div class="bonus-steps">
            <article><span>1</span><div><h3>Sign in</h3><p>The offer is attached to your local Vamoseone account.</p></div></article>
            <article><span>2</span><div><h3>Claim once</h3><p>One claim is allowed per player and stored in your bonus history.</p></div></article>
            <article><span>3</span><div><h3>Play agents</h3><p>The Birr balance can be used with Lucky Dice, Coin Flip, and High / Low.</p></div></article>
        </div>
    </section>
</div>
@endsection
