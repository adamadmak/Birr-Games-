@extends('layouts.app')

@section('title', 'My Profile - Lucy Play')

@section('content')
<div class="player-account-page profile-page">
    <header class="player-page-heading">
        <p>Player account</p>
        <h1>My Profile</h1>
        <span>Your local identity and gameplay snapshot.</span>
    </header>

    <section class="profile-hero-card">
        <div class="profile-avatar-large">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
        <div class="profile-identity"><small>Lucy Play member</small><h2>{{ auth()->user()->name }}</h2><p>{{ auth()->user()->handle() }}{{ auth()->user()->phone ? ' · '.\App\Support\PhoneNumber::format(auth()->user()->phone) : '' }}</p></div>
        <a class="profile-wallet-link" href="{{ route('player.wallet') }}"><span>Birr balance</span><strong>{{ \App\Support\Money::format(auth()->user()->credits) }}</strong><small>Open wallet &#8594;</small></a>
    </section>

    <div class="profile-grid">
        <section class="profile-details-card">
            <div class="wallet-section-title"><div><p>Account details</p><h2>Player information</h2></div><span>Local</span></div>
            <dl>
                <div><dt>Display name</dt><dd>{{ auth()->user()->name }}</dd></div>
                <div><dt>Username</dt><dd>{{ auth()->user()->username ? '@'.auth()->user()->username : 'Not set' }}</dd></div>
                <div><dt>Phone</dt><dd>
                    @if(auth()->user()->phone)
                        {{ \App\Support\PhoneNumber::format(auth()->user()->phone) }}
                    @else
                        @php($telegramBot = app(\App\Services\TelegramBotService::class)->botUsername())
                        @if ($telegramBot !== '')
                            <a class="profile-phone-missing" href="https://t.me/{{ $telegramBot }}" target="_blank" rel="noopener">Share your number in Telegram &#8594;</a>
                        @endif
                    @endif
                </dd></div>
                <div><dt>Role</dt><dd>{{ str(auth()->user()->role)->title() }}</dd></div>
                <div><dt>Member since</dt><dd>{{ auth()->user()->created_at->format('M Y') }}</dd></div>
            </dl>
        </section>

        <section class="profile-stats-card">
            <div class="wallet-section-title"><div><p>Gameplay</p><h2>Your quick stats</h2></div></div>
            <div class="profile-stat"><span>Total rounds</span><strong>{{ number_format($roundCount) }}</strong></div>
            <div class="profile-stat"><span>Winning rounds</span><strong>{{ number_format($winCount) }}</strong></div>
            <div class="profile-stat"><span>Most played</span><strong>{{ $favoriteGame ? str($favoriteGame)->replace('-', ' ')->title() : 'Start playing' }}</strong></div>
        </section>
    </div>

    <div class="profile-actions">
        <a class="button button-primary" href="{{ route('home') }}">Return to lobby</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="button profile-logout-button" type="submit">Log out</button></form>
    </div>
</div>
@endsection
