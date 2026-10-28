@extends('layouts.app')

@section('title', __('My Profile').' - Vamoseone')

@section('content')
<div class="player-account-page profile-page">
    <header class="player-page-heading">
        <p>{{ __('Player account') }}</p>
        <h1>{{ __('My Profile') }}</h1>
        <span>{{ __('Your local identity and gameplay snapshot.') }}</span>
    </header>

    <section class="profile-hero-card">
        <div class="profile-avatar-large">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
        <div class="profile-identity"><small>{{ __('Vamoseone member') }}</small><h2>{{ auth()->user()->name }}</h2><p>{{ auth()->user()->handle() }}{{ auth()->user()->phone ? ' · '.\App\Support\PhoneNumber::format(auth()->user()->phone) : '' }}</p></div>
        <a class="profile-wallet-link" href="{{ route('player.wallet') }}"><span>{{ __('Birr balance') }}</span><strong>{{ \App\Support\Money::format(auth()->user()->credits) }}</strong><small>{{ __('Open wallet') }} &#8594;</small></a>
    </section>

    <div class="profile-grid">
        <section class="profile-details-card">
            <div class="wallet-section-title"><div><p>{{ __('Account details') }}</p><h2>{{ __('Player information') }}</h2></div><span>{{ __('Local') }}</span></div>
            <dl>
                <div><dt>{{ __('Display name') }}</dt><dd>{{ auth()->user()->name }}</dd></div>
                <div><dt>{{ __('Username') }}</dt><dd>{{ auth()->user()->username ? '@'.auth()->user()->username : __('Not set') }}</dd></div>
                <div><dt>{{ __('Phone') }}</dt><dd>
                    @if(auth()->user()->phone)
                        {{ \App\Support\PhoneNumber::format(auth()->user()->phone) }}
                    @else
                        @php($telegramBot = app(\App\Services\TelegramBotService::class)->botUsername())
                        @if ($telegramBot !== '')
                            <a class="profile-phone-missing" href="https://t.me/{{ $telegramBot }}" target="_blank" rel="noopener">{{ __('Share your number in Telegram') }} &#8594;</a>
                        @endif
                    @endif
                </dd></div>
                <div><dt>{{ __('Telegram') }}</dt><dd>
                    @php($telegramLinker = app(\App\Services\TelegramAccountLinker::class))
                    @if($telegramLinker->isLinked(auth()->user()))
                        <a class="profile-telegram-link is-on" href="{{ route('player.telegram.show') }}">&#10003; {{ __('Connected') }}</a>
                    @elseif($telegramLinker->available())
                        <a class="profile-telegram-link" href="{{ route('player.telegram.show') }}">{{ __('Connect Telegram') }} &#8594;</a>
                    @else
                        {{ __('Not available') }}
                    @endif
                </dd></div>
                <div><dt>{{ __('Role') }}</dt><dd>{{ __(str(auth()->user()->role)->title()->toString()) }}</dd></div>
                <div><dt>{{ __('Member since') }}</dt><dd>{{ auth()->user()->created_at->format('M Y') }}</dd></div>
            </dl>
        </section>

        <section class="profile-stats-card">
            <div class="wallet-section-title"><div><p>{{ __('Gameplay') }}</p><h2>{{ __('Your quick stats') }}</h2></div></div>
            <div class="profile-stat"><span>{{ __('Total rounds') }}</span><strong>{{ number_format($roundCount) }}</strong></div>
            <div class="profile-stat"><span>{{ __('Winning rounds') }}</span><strong>{{ number_format($winCount) }}</strong></div>
            <div class="profile-stat"><span>{{ __('Most played') }}</span><strong>{{ $favoriteGame ? str($favoriteGame)->replace('-', ' ')->title() : __('Start playing') }}</strong></div>
        </section>
    </div>

    <div class="profile-actions">
        <a class="button button-primary" href="{{ route('home') }}">{{ __('Return to lobby') }}</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="button profile-logout-button" type="submit">{{ __('Log out') }}</button></form>
    </div>
</div>
@endsection
