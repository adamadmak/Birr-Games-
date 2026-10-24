@extends('layouts.app')

@section('title', 'Vamoseone — Sports betting & casino')

@section('content')
{{-- The front page. The sportsbook is the product; the hero above it is a
     short brand band, not a billboard, so the odds board starts within the
     first screen. A casino shelf under the book links through to /casino. --}}
<div class="sports-home" data-view="sports">
    {{-- Phones get the lobby's promo banner instead of the brand band. --}}
    <div class="home-mobile-banner">@include('partials.hero-banner')</div>

    <section class="sports-hero" aria-labelledby="sports-hero-title">
        <div class="sports-hero-pitch" aria-hidden="true"></div>
        <div class="sports-hero-copy">
            <span class="sports-eyebrow"><i></i>{{ __('Betting & Casino') }}</span>
            <h1 id="sports-hero-title">{{ __('Back your team.') }} <em>{{ __('Win in Birr.') }}</em></h1>
            <p>{{ __('Pre-match and live odds on football, basketball and more, settled straight to your wallet. Spin the casino between kick-offs.') }}</p>
            <div class="sports-hero-actions">
                <a class="button button-primary" href="#sportsbook">{{ __('Bet now') }}</a>
                <a class="button button-soft" href="{{ route('home') }}">{{ __('Open casino') }}</a>
            </div>
        </div>
        <ul class="sports-hero-perks" aria-label="{{ __('Why bet here') }}">
            <li><strong>{{ __('Live') }}</strong><span>{{ __('In-play odds on every match') }}</span></li>
            <li><strong>{{ \App\Support\Money::code() }}</strong><span>{{ __('Deposit and withdraw in Birr') }}</span></li>
            <li><strong>24/7</strong><span>{{ __('Support on Telegram') }}</span></li>
        </ul>
        <svg class="sports-hero-ball" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
            <circle cx="50" cy="50" r="48" fill="rgba(255,255,255,.92)"/>
            <path fill="#0b1a45" d="M50 22l15 11-6 18H41l-6-18zM23 44l15 5 5 16-10 12-13-9zM77 44l3 24-13 9-10-12 5-16zM34 86l16-7 16 7-3 7a46 46 0 0 1-26 0zM50 2a48 48 0 0 1 20 5l-5 11-15-4-15 4-5-11a48 48 0 0 1 20-5z" opacity=".9"/>
            <circle cx="50" cy="50" r="48" fill="none" stroke="#0b1a45" stroke-width="3" opacity=".9"/>
        </svg>
    </section>

    @include('partials.win-ticker')

    {{-- Phones: one thing at a time. The switch shows the book or the casino
         shelf; desktop shows both and hides the switch. --}}
    <div class="home-switch" role="tablist" aria-label="{{ __('Home sections') }}">
        <button type="button" role="tab" aria-selected="true" data-home-view="sports">{{ __('Sports') }}</button>
        <button type="button" role="tab" aria-selected="false" data-home-view="casino">{{ __('Casino') }}</button>
        <a role="tab" aria-selected="false" href="{{ route('promotions') }}">{{ __('Promos') }}</a>
    </div>

    <section class="sports-book" id="sportsbook" aria-label="{{ __('Sportsbook') }}">
        <iframe
            src="{{ $bookSrc }}"
            title="{{ __('Sportsbook') }}"
            allow="fullscreen"
            referrerpolicy="strict-origin-when-cross-origin"></iframe>
    </section>

    @if ($casinoPicks->isNotEmpty())
        <section class="home-casino" aria-labelledby="home-casino-title">
            <div class="home-casino-head">
                <div>
                    <p class="sports-eyebrow">{{ __('Between the matches') }}</p>
                    <h2 id="home-casino-title">{{ __('Casino picks') }}</h2>
                </div>
                <a class="home-casino-all" href="{{ route('home') }}">{{ __('Open casino') }} <span aria-hidden="true">&rarr;</span></a>
            </div>
            <div class="home-casino-rail">
                @foreach ($casinoPicks as $game)
                    <a class="game-card home-casino-card" href="{{ route('games.show', $game) }}">
                        <div class="game-art art-{{ $game->art }} {{ $game->image_source ? 'has-image' : '' }}">
                            @if ($game->image_source)<img class="game-cover" src="{{ $game->image_source }}" alt="{{ $game->display_title }}" loading="lazy" decoding="async">@endif
                            <span class="game-tag">{{ $game->tag }}</span>
                            <span class="game-symbol">{{ $game->symbol }}</span><span class="art-glow"></span><span class="card-overlay"></span><span class="play-button">&#9654;</span>
                        </div>
                        <div class="game-info"><strong>{{ $game->display_title }}</strong><small>{{ $game->provider->name }}</small></div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
<script>
(function () {
    var root = document.querySelector('.sports-home');
    if (!root) return;
    var tabs = root.querySelectorAll('[data-home-view]');
    Array.prototype.forEach.call(tabs, function (tab) {
        tab.addEventListener('click', function () {
            root.setAttribute('data-view', tab.getAttribute('data-home-view'));
            Array.prototype.forEach.call(tabs, function (t) { t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
        });
    });
})();
</script>
@endsection
