@extends('layouts.app')

@section('title', 'Lucy Play — Game lobby')

@section('content')
@auth
<div class="member-lobby">
    @if (! empty($predictionPool))
        @include('partials.prediction-pool')
    @endif

    <div class="member-deposit-strip">
        <div class="member-deposit-strip-copy">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="opacity:.75"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <span>{{ __(':count games online', ['count' => number_format($playableGameCount)]) }}</span>
        </div>
        <a class="member-deposit-strip-cta" href="{{ route('player.wallet', ['tab' => 'deposit']) }}">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg>
            {{ __('Deposit') }}
        </a>
    </div>

    @if(($recentGames ?? collect())->isNotEmpty())
        {{-- Compact glass strip of the player's own recent games, matching the
             reference: a thumbnail rail, not a shelf. --}}
        <section class="recent-rail" aria-label="{{ __('Continue playing') }}">
            <div class="recent-rail-track">
                @foreach ($recentGames as $game)
                    <a class="recent-chip" href="{{ route('games.show', $game) }}"
                       title="{{ $game->display_title }}{{ $game->last_played_at ? ' — '.$game->last_played_at->diffForHumans() : '' }}">
                        <span class="recent-chip-art">
                            @if($game->image_source)
                                <img src="{{ $game->image_source }}" alt="" loading="lazy" decoding="async" width="48" height="48">
                            @else
                                <em>{{ $game->symbol }}</em>
                            @endif
                        </span>
                        <span class="recent-chip-name">{{ $game->display_title }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif


    <form class="member-lobby-toolbar" method="GET" action="{{ route('home') }}" aria-label="Game filters" data-server-query="{{ $catalogSearch }}" data-server-provider="{{ $catalogProvider }}">
        <label class="member-search-box"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg><input type="search" name="q" value="{{ $catalogSearch }}" placeholder="{{ __('Search games...') }}" data-game-search></label>
        <label class="member-provider-select"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line></svg><select name="provider" data-provider-filter aria-label="Filter by provider"><option value="">{{ __('All providers') }}</option>@foreach ($providers as $provider)<option value="{{ $provider->name }}" @selected($catalogProvider === $provider->name)>{{ $provider->name }} ({{ number_format($provider->games_count) }})</option>@endforeach</select></label>
    </form>

    <div class="empty-games member-empty-games" data-searching-games hidden><span>&#9906;</span><h2>{{ __('Searching the catalogue…') }}</h2><p>{{ __('Looking beyond the games on this page.') }}</p></div>
    <div class="empty-games member-empty-games" data-empty-games hidden><span>&#9906;</span><h2>{{ __('No games found') }}</h2><p>{{ __('Try another title or provider.') }}</p></div>

    {{-- Everything the server re-renders for a search lives in here, so typing can
         replace the results in place instead of navigating. The empty and searching
         notices stay outside: the script holds references to them. --}}
    <div data-game-results>
    @if($trendingGames->isNotEmpty())
        <section class="member-game-section" id="trending" data-game-section>
            <div class="member-section-heading">
                <div><h2>{{ __('Top Games') }}</h2></div>
                <button type="button" data-collapse aria-expanded="true">{{ __('Collapse') }} <span>&minus;</span></button>
            </div>
            <div class="member-game-grid" data-game-grid>
                @foreach ($trendingGames as $game)
                    <a class="member-game-card game-card" href="{{ route('games.show', $game) }}" data-game-card data-title="{{ strtolower($game->title) }}" data-provider="{{ $game->provider->name }}" data-modal-title="{{ $game->display_title }}" data-modal-image="{{ $game->image_source }}" data-modal-slug="{{ $game->slug }}" data-modal-launch="{{ $game->launchesViaProvider() ? route('games.provider.launch',$game) : route('games.show',$game) }}" data-modal-launch-method="{{ $game->launchesViaProvider() ? 'post' : 'get' }}">
                        <div class="game-art art-{{ $game->art }} {{ $game->image_source ? 'has-image' : '' }}">
                            @if($game->image_source)<img class="game-cover" src="{{ $game->image_source }}" alt="{{ $game->display_title }}" loading="lazy" decoding="async">@endif
                            <span class="game-tag">{{ $game->tag }}</span>
                            <span class="game-symbol">{{ $game->symbol }}</span><span class="art-glow"></span><span class="card-overlay"></span><span class="play-button">&#9654;</span>
                        </div>
                        <div class="game-info"><strong>{{ $game->display_title }}</strong></div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @foreach ($providerSections as $providerName => $providerGames)
        <section class="member-game-section" id="provider-{{ \Illuminate\Support\Str::slug($providerName) }}" data-game-section>
            <div class="member-section-heading">
                <div><h2>{{ $providerName }}</h2></div>
                <button type="button" data-collapse aria-expanded="true">{{ __('Collapse') }} <span>&minus;</span></button>
            </div>
            <div class="member-game-grid" data-game-grid>
                @foreach ($providerGames as $game)
                    <a class="member-game-card game-card" href="{{ route('games.show', $game) }}" data-game-card data-title="{{ strtolower($game->title) }}" data-provider="{{ $game->provider->name }}" data-modal-title="{{ $game->display_title }}" data-modal-image="{{ $game->image_source }}" data-modal-slug="{{ $game->slug }}" data-modal-launch="{{ $game->launchesViaProvider() ? route('games.provider.launch',$game) : route('games.show',$game) }}" data-modal-launch-method="{{ $game->launchesViaProvider() ? 'post' : 'get' }}">
                        <div class="game-art art-{{ $game->art }} {{ $game->image_source ? 'has-image' : '' }}">
                            @if($game->image_source)<img class="game-cover" src="{{ $game->image_source }}" alt="{{ $game->display_title }}" loading="lazy" decoding="async">@endif
                            <span class="game-tag">{{ $game->tag }}</span>
                            <span class="game-symbol">{{ $game->symbol }}</span><span class="art-glow"></span><span class="card-overlay"></span><span class="play-button">&#9654;</span>
                        </div>
                        <div class="game-info"><strong>{{ $game->display_title }}</strong></div>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
    @if($catalogPage->hasPages())
        <nav class="catalog-pagination" aria-label="Game catalog pages">
            @if($catalogPage->previousPageUrl())<a href="{{ $catalogPage->previousPageUrl() }}">← Previous games</a>@endif
            @if($catalogPage->nextPageUrl())<a href="{{ $catalogPage->nextPageUrl() }}">More games →</a>@endif
        </nav>
    @endif
    </div>
</div>
@else
<div class="lobby-shell">
        <aside class="lobby-sidebar">
            <a class="player-vip-card" href="{{ route('promotions') }}">
                <img class="player-vip-img" src="{{ asset('images/vip-gems.png') }}" alt="VIP Rewards">
                <div><small>Claim your</small><strong>VIP Rewards</strong></div>
                <span class="player-vip-arrow">&#9654;</span>
            </a>

            <div class="sidebar-deposit-strip">
                <a class="sidebar-deposit-btn" href="{{ route('login') }}">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg>
                    {{ __('Deposit') }}
                </a>
            </div>

        <section class="daily-card">
            <div><span class="daily-icon">◆</span><div><small>Welcome balance</small><strong>{{ \App\Support\Money::format($welcomeBalance) }}</strong></div></div>
            <button type="button" disabled>On signup</button>
        </section>

        <nav class="side-nav" aria-label="Lobby sections">
            <a class="active" href="#trending"><span>⌂</span>Lobby</a>
            <a href="#playable-agents"><span>⚡</span>Game agents</a>
            <a href="{{ route('sportsbook') }}"><span>⚽</span>Sport</a>
            <a href="{{ route('promotions') }}"><span>◇</span>Promotions</a>
        </nav>

        <div class="sidebar-responsible">
            <span>18+</span>
            <p><strong>Stay in control.</strong><br>Pause when play stops being fun.</p>
        </div>
    </aside>

    <div class="lobby-content">
        {{-- The artwork is whatever an operator uploaded under Configuration →
             Frontend → Hero banner (App\Support\HeroBanner), with the bundled
             illustration as the fallback. Phones get their own, taller image
             when one is set; otherwise the wide one is used. --}}
        <section class="hero-banner hero-banner-image">
            @php([$heroW, $heroH] = \App\Support\HeroBanner::size('desktop'))
            @php([$heroMobileW, $heroMobileH] = \App\Support\HeroBanner::size('mobile'))
            {{-- width/height are the uploaded picture's own size: on desktop the
                 hero takes that picture's height, so nothing is cropped. --}}
            <picture class="hero-media">
                <source media="(max-width: 700px)" srcset="{{ \App\Support\HeroBanner::mobileUrl() }}" width="{{ $heroMobileW }}" height="{{ $heroMobileH }}">
                <img src="{{ \App\Support\HeroBanner::desktopUrl() }}" alt="{{ \App\Support\HeroBanner::alt() }}" width="{{ $heroW }}" height="{{ $heroH }}" fetchpriority="high" decoding="async">
            </picture>
            {{-- The picture is the whole banner. The heading stays for screen readers
                 and search engines but is not drawn. --}}
            <h1 class="hero-sr-title">{{ __('Explore it. Play it. Enjoy it.') }}</h1>
        </section>

        <form class="lobby-toolbar" method="GET" action="{{ route('home') }}" aria-label="Game filters" data-server-query="{{ $catalogSearch }}" data-server-provider="{{ $catalogProvider }}">
            <label class="search-box">
                <span>⌕</span>
                <input type="search" name="q" value="{{ $catalogSearch }}" placeholder="Search games or studios" data-game-search>
            </label>
            <label class="provider-select">
                <span>Studio</span>
                <select name="provider" data-provider-filter>
                    <option value="">All studios</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider->name }}" @selected($catalogProvider === $provider->name)>{{ $provider->name }} ({{ number_format($provider->games_count) }})</option>
                    @endforeach
                </select>
            </label>
        </form>

        <div class="empty-games" data-searching-games hidden>
            <span>⌕</span><h2>{{ __('Searching the catalogue…') }}</h2><p>{{ __('Looking beyond the games on this page.') }}</p>
        </div>
        <div class="empty-games" data-empty-games hidden>
            <span>⌕</span><h2>No games found</h2><p>Try another title or studio.</p>
        </div>

        <div data-game-results>
        @foreach ($guestSections as $category)
            <section class="game-section" id="{{ $category->slug }}" data-game-section>
                <div class="section-heading">
                    <div><p>{{ $category->description }}</p><h2>{{ $category->name }}</h2></div>
                    <button type="button" data-collapse aria-expanded="true">Collapse <span>−</span></button>
                </div>
                <div class="game-grid" data-game-grid>
                    @foreach ($category->games as $game)
                        <a class="game-card" href="{{ route('games.show', $game) }}"
                           data-game-card data-title="{{ strtolower($game->title) }}" data-provider="{{ $game->provider->name }}" data-modal-title="{{ $game->display_title }}" data-modal-image="{{ $game->image_source }}" data-modal-slug="{{ $game->slug }}" data-modal-launch="{{ route('login') }}">
                            <div class="game-art art-{{ $game->art }} {{ $game->image_source ? 'has-image' : '' }}">
                            @if($game->image_source)<img class="game-cover" src="{{ $game->image_source }}" alt="{{ $game->display_title }}" loading="lazy" decoding="async">@endif
                                <span class="game-tag">{{ $game->tag }}</span>
                                <span class="game-symbol">{{ $game->symbol }}</span>
                                <span class="art-glow"></span>
                                <span class="card-overlay"></span>
                                <span class="play-button">▶</span>
                            </div>
                            <div class="game-info">
                                <strong>{{ $game->display_title }}</strong>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
        @if($catalogPage->hasPages())
            <nav class="catalog-pagination" aria-label="Game catalog pages">
                @if($catalogPage->previousPageUrl())<a href="{{ $catalogPage->previousPageUrl() }}">← Previous games</a>@endif
                @if($catalogPage->nextPageUrl())<a href="{{ $catalogPage->nextPageUrl() }}">More games →</a>@endif
            </nav>
        @endif
        </div>
    </div>
</div>
@endauth

<dialog class="lobby-game-dialog" data-lobby-game-dialog aria-labelledby="lobby-game-dialog-title">
    <button class="lobby-game-dialog-close" type="button" data-lobby-game-close aria-label="Close game details">&times;</button>
    <div class="lobby-game-dialog-art"><img alt="" data-lobby-game-image hidden></div>
    <div class="lobby-game-dialog-title-row"><h2 id="lobby-game-dialog-title" data-lobby-game-title></h2><button type="button" class="lobby-game-favorite" data-lobby-game-favorite aria-pressed="false"><span>Add to favorite</span><i>♡</i></button></div>
    <div class="lobby-game-dialog-actions"><a class="primary" href="#" data-lobby-game-play>Play Now</a></div>
</dialog>
@endsection
