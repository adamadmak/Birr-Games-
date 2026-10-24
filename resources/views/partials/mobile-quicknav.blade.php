{{-- Phone-only strip of quick links under the top bar, in the style of a
     betting app's icon row. Desktop hides it (see PHONE CHROME in lucy-play.css);
     the sidebar and top navigation carry the same destinations there. --}}
<nav class="player-quicknav" aria-label="{{ __('Quick links') }}">
    <a class="{{ request()->routeIs('sportsbook') ? 'active' : '' }}" href="{{ url('/') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/></svg>
        <span>{{ __('Home') }}</span>
    </a>
    <a href="{{ url('/#sportsbook') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2"/><path d="M9.5 2h5"/><path d="M12 2v3"/><path d="M19 6l1.5-1.5"/></svg>
        <span>{{ __('Sport') }}</span>
    </a>
    <a href="{{ url('/#sportsbook') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="2.2"/><path d="M16.2 7.8a6 6 0 0 1 0 8.4M7.8 16.2a6 6 0 0 1 0-8.4"/><path d="M19.1 4.9a10 10 0 0 1 0 14.2M4.9 19.1a10 10 0 0 1 0-14.2"/></svg>
        <span>{{ __('Live') }}</span>
    </a>
    <a class="{{ request()->routeIs('home', 'games.*') ? 'active' : '' }}" href="{{ route('home') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M9 5v14M15 5v14"/><path d="M5 9.5h2l-1.2 5M11 9.5h2l-1.2 5M17 9.5h2l-1.2 5"/></svg>
        <span>{{ __('Games') }}</span>
    </a>
    <a class="{{ request()->routeIs('promotions') ? 'active' : '' }}" href="{{ route('promotions') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v8h14v-8"/><path d="M12 8v12"/><path d="M12 8c-2.4-3.4-6.4-3-6.2-.8.2 1.8 3.6 1.6 6.2.8zM12 8c2.4-3.4 6.4-3 6.2-.8-.2 1.8-3.6 1.6-6.2.8z"/></svg>
        <span>{{ __('Promos') }}</span>
    </a>
    <a class="{{ request()->routeIs('player.tournament') ? 'active' : '' }}" href="{{ auth()->check() ? route('player.tournament') : route('login') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 21h8M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"/></svg>
        <span>{{ __('Tournament') }}</span>
    </a>
    <a class="{{ request()->routeIs('deposit-bonus') ? 'active' : '' }}" href="{{ route('deposit-bonus') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
        <span>{{ __('Bonus') }}</span>
    </a>
</nav>
