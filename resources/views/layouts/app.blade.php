<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-locale="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#070d1c">
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="Vamoseone is a local Ethiopian game experience with a Birr-denominated wallet.">

    {{-- Tab icon, home-screen icon, and the manifest that makes the site installable.
         The icons are the lion crest on the app's own ground, so they read the same
         against a light or a dark browser chrome. --}}
    <link rel="icon" href="/icons/icon-32.png" sizes="32x32" type="image/png">
    <link rel="icon" href="/icons/icon-16.png" sizes="16x16" type="image/png">
    <link rel="icon" href="/icons/icon-192.png" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="application-name" content="{{ config('app.name') }}">
    {{-- iOS ignores the manifest's display mode; these are how an added-to-home-screen
         shortcut opens without Safari's chrome. --}}
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <script src="https://telegram.org/js/telegram-web-app.js?v=8.0"></script>
    <script>
        (function() {
            var tg = window.Telegram && window.Telegram.WebApp;
            var authSent = false;
            var fullscreenDone = false;

            function initTelegram() {
                if (!tg) return;
                try { tg.ready(); } catch(e) {}
                try { tg.expand(); } catch(e) {}
                try { if (typeof tg.setHeaderColor === 'function') tg.setHeaderColor('#0b0b0c'); } catch(e) {}
                try { if (typeof tg.setBackgroundColor === 'function') tg.setBackgroundColor('#0b0b0c'); } catch(e) {}
                try { if (typeof tg.disableVerticalSwipes === 'function') tg.disableVerticalSwipes(); } catch(e) {}
                try { if (typeof tg.enableClosingConfirmation === 'function') tg.enableClosingConfirmation(); } catch(e) {}
                try { if (tg.BackButton) tg.BackButton.hide(); } catch(e) {}
                tryFullscreen();
                tryAutoAuth();
                applySafeAreaInset();
                bindSafeAreaEvents();
            }

            function tryFullscreen() {
                if (!tg || fullscreenDone) return;
                try {
                    if (typeof tg.requestFullscreen === 'function' && !tg.isFullscreen) {
                        tg.requestFullscreen();
                        fullscreenDone = true;
                    }
                } catch(e) {}
            }

            /*
             * In fullscreen mode Telegram draws its own close/menu controls directly on
             * top of the webview instead of in a native header, so our topbar and its
             * Login/Deposit/Wallet buttons sat right underneath them — the tap landed on
             * Telegram's overlay, not on our page, which is why some users could not press
             * Login at all. contentSafeAreaInset.top is exactly the height Telegram
             * reserves for that overlay; pushing our topbar down by that amount clears it.
             * It is 0 outside fullscreen, so this is a no-op everywhere else.
             */
            var safeAreaBound = false;

            function currentTopInset() {
                if (!tg) return 0;
                var content = tg.contentSafeAreaInset && typeof tg.contentSafeAreaInset.top === 'number' ? tg.contentSafeAreaInset.top : 0;
                var device = tg.safeAreaInset && typeof tg.safeAreaInset.top === 'number' ? tg.safeAreaInset.top : 0;
                return Math.max(content, device, 0);
            }

            function applySafeAreaInset() {
                if (!tg) return;
                try {
                    document.documentElement.style.setProperty('--tg-top-inset', currentTopInset() + 'px');
                } catch(e) {}
            }

            function bindSafeAreaEvents() {
                if (!tg || safeAreaBound || typeof tg.onEvent !== 'function') return;
                safeAreaBound = true;
                try { tg.onEvent('contentSafeAreaChanged', applySafeAreaInset); } catch(e) {}
                try { tg.onEvent('safeAreaChanged', applySafeAreaInset); } catch(e) {}
                try { tg.onEvent('fullscreenChanged', applySafeAreaInset); } catch(e) {}
                try { tg.onEvent('viewportChanged', applySafeAreaInset); } catch(e) {}
            }

            function needsAuth() {
                if (!document.body.classList.contains('player-authenticated')) return true;

                /* The session may belong to a different (or merged away) player than the
                   Telegram account currently viewing the mini app. */
                var linked = document.body.dataset.telegramUserId || '';
                var viewer = (tg.initDataUnsafe && tg.initDataUnsafe.user && tg.initDataUnsafe.user.id)
                    ? String(tg.initDataUnsafe.user.id)
                    : '';

                return viewer !== '' && linked !== viewer;
            }

            function tryAutoAuth() {
                if (!tg || authSent) return;
                if (tg.initData && needsAuth()) {
                    authSent = true;
                    fetch('/telegram/webapp-auth', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        },
                        body: JSON.stringify({ init_data: tg.initData })
                    }).then(function(r) { return r.json(); }).then(function(res) {
                        if (res.ok && res.redirect) { window.location.reload(); return; }
                        if (res && res.phone_required) {
                            /* Only nag once per visit, since this runs on every page load. */
                            try {
                                if (sessionStorage.getItem('lucy-phone-prompt') === '1') return;
                                sessionStorage.setItem('lucy-phone-prompt', '1');
                            } catch (e) {}
                            try { tg.showAlert(res.error); } catch (e) { alert(res.error); }
                        }
                    }).catch(function() { authSent = false; });
                }
            }

            function onUserGesture() {
                tryFullscreen();
            }

            initTelegram();
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTelegram);
            }
            window.addEventListener('load', initTelegram);
            document.addEventListener('touchstart', onUserGesture, { passive: true });
            document.addEventListener('click', onUserGesture, { passive: true });
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- One variable font covers every weight the stylesheet asks for (300-950),
         replacing five static Urbanist files plus the Outfit and Plus Jakarta
         families that were loaded but only ever used as fallbacks. --}}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Urbanist:wght@300..900&display=swap">
    @if (app()->getLocale() === 'am')
        {{-- Urbanist has no Ge'ez glyphs, so Amharic would fall back to whatever the
             device happens to have. Noto Sans Ethiopic is fetched only for this locale. --}}
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;500;600;700&display=swap">
    @endif
    {{-- Font Awesome was loaded on every player page for four icons on a single
         screen; those are inline SVG now, so the webfont is gone entirely. --}}
    <link rel="stylesheet" href="/css/lucy-play.css?v={{ @filemtime(public_path('css/lucy-play.css')) ?: time() }}">
    {{-- Shows what is being typed into a password box. A stored password is a
         bcrypt hash and cannot be turned back into text, so this is the only
         "view password" there can be. --}}
    <link rel="stylesheet" href="/css/password-reveal.css?v={{ @filemtime(public_path('css/password-reveal.css')) ?: time() }}">
    <script defer src="/js/password-reveal.js?v={{ @filemtime(public_path('js/password-reveal.js')) ?: time() }}"></script>
    {{-- The mark is the first thing painted in the bar and on the splash, so it
         is the likely largest-contentful-paint element. Fetch it alongside the
         stylesheet rather than waiting for the parser to reach the <img>. --}}
    <link rel="preload" as="image" href="{{ asset('logo.png') }}" fetchpriority="high">
    {{-- Per-page additions to <head>: the auth forms push the Turnstile loader here. --}}
    @stack('head')
</head>
@php
    /*
     * The sportsbook is browsable signed-out — pages.sportsbook only mints a
     * launch token when there IS a player — so a guest gets the full player
     * shell there (topbar plus the fixed left nav column) instead of the
     * marketing header, which carries no side navigation at all and left a
     * logged-out visitor with no way out of that page. Every other guest page
     * is untouched.
     *
     * `player-shell` is deliberately a SEPARATE class from
     * `player-authenticated`: the Telegram auto-login below gates on
     * `player-authenticated`, so putting that on a signed-out body would
     * silently stop Telegram users from being logged in on this page. The CSS
     * pairs the two selectors instead.
     */
    $playerShell = auth()->check() || request()->routeIs('sportsbook');
@endphp
<body class="@auth player-authenticated @else player-guest @endauth{{ $playerShell ? ' player-shell' : '' }}" data-telegram-user-id="{{ auth()->user()?->telegramSubscriber?->telegram_user_id }}">
    <div class="splash-screen" id="splashScreen">
        <img class="splash-logo" src="{{ asset('logo.png') }}" alt="Vamoseone" width="816" height="306" fetchpriority="high" decoding="async">
        <div class="splash-loading-track">
            <div class="splash-loading-bar"></div>
        </div>
    </div>
    <div class="ambient ambient-one"></div>
    <div class="ambient ambient-two"></div>

    @if ($playerShell)
        <div class="player-app-shell">
            <header class="player-topbar">
                <button class="player-menu-toggle" type="button" data-player-menu-toggle aria-label="Open player navigation">&#9776;</button>

                <a class="brand player-brand" href="{{ url('/') }}" aria-label="Vamoseone home">
                    <img class="brand-logo" src="{{ asset('logo.png') }}" alt="Vamoseone logo" width="816" height="306" fetchpriority="high" decoding="async">
                </a>
                {{-- Phone only: the lobby carries the game search. --}}
                <a class="player-search-link" href="{{ route('home') }}" aria-label="{{ __('Search games') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.8-3.8"/></svg></a>

                <nav class="player-topnav" aria-label="Primary navigation">
                    <a class="{{ request()->routeIs('sportsbook') ? 'active' : '' }}" href="{{ route('sportsbook') }}">{{ __('Sports') }}</a>
                    <a class="{{ request()->routeIs('home', 'games.*') ? 'active' : '' }}" href="{{ route('home') }}">{{ __('Casino') }}</a>
                    <a class="{{ request()->routeIs('promotions') ? 'active' : '' }}" href="{{ route('promotions') }}">{{ __('Promotions') }}</a>
                    @auth
                        @if (auth()->user()->isAdmin())<a href="{{ route('admin.dashboard') }}">{{ __('Admin') }}</a>@endif
                    @endauth
                </nav>

                {{-- A guest reaches this shell on the sportsbook only. Every
                     account action behind it is auth middleware, so showing it
                     signed-out would just bounce to /login — offer the sign-in
                     instead, which is what they actually need to place a bet. --}}
                <div class="player-account-actions">
                    @auth
                        <a class="player-wallet-pill" href="{{ route('player.wallet') }}" aria-label="Open wallet">
                            <span><small>{{ \App\Support\Money::code() }}</small><strong data-balance-pill aria-live="polite">{{ \App\Support\Money::amount(auth()->user()->credits) }}</strong></span>
                            <em>{{ __('Wallet') }}</em>
                        </a>
                        <a class="player-deposit-btn" href="{{ route('player.wallet', ['tab' => 'deposit']) }}">{{ __('Deposit') }}</a>
                        <a class="player-profile-link" href="{{ route('player.profile') }}" aria-label="Open profile for {{ auth()->user()->name }}">
                            <span class="player-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                        </a>
                    @else
                        <a class="player-auth-btn player-auth-login" href="{{ route('login') }}">{{ __('Log in') }}</a>
                        <a class="player-auth-btn player-auth-register" href="{{ route('register') }}">{{ __('Register') }}</a>
                    @endauth
                </div>
            </header>

            <aside class="player-sidebar" data-player-sidebar>
                <a class="player-vip-card" href="{{ route('promotions') }}">
                    <img class="player-vip-img" src="{{ asset('images/vip-gems.png') }}" alt="VIP Rewards">
                    <div><small>{{ __('Claim your') }}</small><strong>{{ __('VIP Rewards') }}</strong></div>
                    <span class="player-vip-arrow">&#9654;</span>
                </a>

                <div class="sidebar-deposit-strip">
                    <a class="sidebar-deposit-btn" href="{{ route('player.wallet', ['tab' => 'deposit']) }}">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg>
                        Deposit
                    </a>
                </div>

                @auth
                    <section class="player-daily-strip">
                        <span>{{ __('Current balance') }}</span><strong data-balance-formatted aria-live="polite">{{ \App\Support\Money::exact(auth()->user()->credits) }}</strong>
                    </section>
                @endauth

                <p class="side-heading">{{ __('Menu') }}</p>
                <nav class="player-side-nav" aria-label="Player navigation">
                    <a class="{{ request()->routeIs('sportsbook') ? 'active' : '' }}" href="{{ route('sportsbook') }}"><span><svg class="side-ico" viewBox="0 0 512 512" width="18" height="18" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><circle cx="256" cy="256" r="192" fill="none" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="32"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" d="M256 175.15l-76.09 63.83L200 320h112l20.09-81.02L256 175.15zM332.09 238.98l52.87-22.4 25.78-73.26M447 269.97l-62.04-53.39M179.91 238.98l-52.87-22.4-25.78-73.26M65 269.97l62.04-53.39M256 175.15v-57.57l64-42.64M192 74.93l64 42.65M312 320l28 48-28 71M410.74 368H342M200 320l-28 48 28.37 71.5M101.63 368H172"/></svg></span>{{ __('Sports') }}</a>
                    <a class="{{ request()->routeIs('home', 'games.*') ? 'active' : '' }}" href="{{ route('home') }}"><span><svg class="side-ico" viewBox="0 0 512 512" width="18" height="18" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path d="M467.51 248.83c-18.4-83.18-45.69-136.24-89.43-149.17A91.5 91.5 0 00352 96c-26.89 0-48.11 16-96 16s-69.15-16-96-16a99.09 99.09 0 00-27.2 3.66C89 112.59 61.94 165.7 43.33 248.83c-19 84.91-15.56 152 21.58 164.88 26 9 49.25-9.61 71.27-37 25-31.2 55.79-40.8 119.82-40.8s93.62 9.6 118.66 40.8c22 27.41 46.11 45.79 71.42 37.16 41.02-14.01 40.44-79.13 21.43-165.04z" fill="none" stroke="currentColor" stroke-miterlimit="10" stroke-width="32"/><circle cx="292" cy="224" r="20"/><path d="M336 288a20 20 0 1120-19.95A20 20 0 01336 288z"/><circle cx="336" cy="180" r="20"/><circle cx="380" cy="224" r="20"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" d="M160 176v96M208 224h-96"/></svg></span>{{ __('Casino') }}</a>
                    <a class="{{ request()->routeIs('promotions') ? 'active' : '' }}" href="{{ route('promotions') }}"><span><svg class="side-ico" viewBox="0 0 512 512" width="18" height="18" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path d="M256 104v56h56a56 56 0 10-56-56zM256 104v56h-56a56 56 0 1156-56z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="32"/><rect x="64" y="160" width="384" height="112" rx="32" ry="32" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="32"/><path d="M416 272v144a48 48 0 01-48 48H144a48 48 0 01-48-48V272M256 160v304" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="32"/></svg></span>{{ __('Promotions') }}</a>
                    @auth
                        <a class="{{ request()->routeIs('player.tournament') ? 'active' : '' }}" href="{{ route('player.tournament') }}"><span><svg class="side-ico" viewBox="0 0 512 512" width="18" height="18" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" d="M176 464h160M256 464V336M384 224c0-50.64-.08-134.63-.12-160a16 16 0 00-16-16l-223.79.26a16 16 0 00-16 15.95c0 30.58-.13 129.17-.13 159.79 0 64.28 83 112 128 112S384 288.28 384 224z"/><path d="M128 96H48v16c0 55.22 33.55 112 80 112M384 96h80v16c0 55.22-33.55 112-80 112" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="32"/></svg></span>{{ __('Daily Tournament') }}</a>
                        <a class="{{ request()->routeIs('deposit-bonus') ? 'active' : '' }}" href="{{ route('deposit-bonus') }}"><span><svg class="side-ico" viewBox="0 0 512 512" width="18" height="18" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path d="M259.92 262.91L216.4 149.77a9 9 0 00-16.8 0l-43.52 113.14a9 9 0 01-5.17 5.17L37.77 311.6a9 9 0 000 16.8l113.14 43.52a9 9 0 015.17 5.17l43.52 113.14a9 9 0 0016.8 0l43.52-113.14a9 9 0 015.17-5.17l113.14-43.52a9 9 0 000-16.8l-113.14-43.52a9 9 0 01-5.17-5.17zM108 68L88 16 68 68 16 88l52 20 20 52 20-52 52-20-52-20zM426.67 117.33L400 48l-26.67 69.33L304 144l69.33 26.67L400 240l26.67-69.33L496 144l-69.33-26.67z" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="32"/></svg></span>{{ __('Deposit bonus') }}</a>
                        <a class="{{ request()->routeIs('shamo*') ? 'active' : '' }}" href="{{ route('shamo') }}"><span><svg class="side-ico" viewBox="0 0 512 512" width="18" height="18" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-miterlimit="10" stroke-width="32" d="M366.05 146a46.7 46.7 0 01-2.42-63.42 3.87 3.87 0 00-.22-5.26l-44.13-44.18a3.89 3.89 0 00-5.5 0l-70.34 70.34a23.62 23.62 0 00-5.71 9.24h0a23.66 23.66 0 01-14.95 15h0a23.7 23.7 0 00-9.25 5.71L33.14 313.78a3.89 3.89 0 000 5.5l44.13 44.13a3.87 3.87 0 005.26.22 46.69 46.69 0 0165.84 65.84 3.87 3.87 0 00.22 5.26l44.13 44.13a3.89 3.89 0 005.5 0l180.4-180.39a23.7 23.7 0 005.71-9.25h0a23.66 23.66 0 0114.95-15h0a23.62 23.62 0 009.24-5.71l70.34-70.34a3.89 3.89 0 000-5.5l-44.13-44.13a3.87 3.87 0 00-5.26-.22 46.7 46.7 0 01-63.42-2.32z"/><path fill="none" stroke="currentColor" stroke-miterlimit="10" stroke-width="32" stroke-linecap="round" d="M250.5 140.44l-16.51-16.51M294.52 184.46l-11.01-11M338.54 228.49l-11-11.01M388.07 278.01l-16.51-16.51"/></svg></span>{{ __('Shamo') }}</a>
                        <a class="{{ request()->routeIs('player.wallet') ? 'active' : '' }}" href="{{ route('player.wallet') }}"><span><svg class="side-ico" viewBox="0 0 512 512" width="18" height="18" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><rect x="48" y="144" width="416" height="288" rx="48" ry="48" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="32"/><path d="M411.36 144v-30A50 50 0 00352 64.9L88.64 109.85A50 50 0 0048 159v49" fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="32"/><path d="M368 320a32 32 0 1132-32 32 32 0 01-32 32z"/></svg></span>{{ __('Birr wallet') }}</a>
                        <a class="{{ request()->routeIs('player.profile') ? 'active' : '' }}" href="{{ route('player.profile') }}"><span><svg class="side-ico" viewBox="0 0 512 512" width="18" height="18" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path d="M258.9 48C141.92 46.42 46.42 141.92 48 258.9c1.56 112.19 92.91 203.54 205.1 205.1 117 1.6 212.48-93.9 210.88-210.88C462.44 140.91 371.09 49.56 258.9 48zm126.42 327.25a4 4 0 01-6.14-.32 124.27 124.27 0 00-32.35-29.59C321.37 329 289.11 320 256 320s-65.37 9-90.83 25.34a124.24 124.24 0 00-32.35 29.58 4 4 0 01-6.14.32A175.32 175.32 0 0180 259c-1.63-97.31 78.22-178.76 175.57-179S432 158.81 432 256a175.32 175.32 0 01-46.68 119.25z"/><path d="M256 144c-19.72 0-37.55 7.39-50.22 20.82s-19 32-17.57 51.93C191.11 256 221.52 288 256 288s64.83-32 67.79-71.24c1.48-19.74-4.8-38.14-17.68-51.82C293.39 151.44 275.59 144 256 144z"/></svg></span>{{ __('My profile') }}</a>
                    @endauth
                    {{-- Revealed by script only where the app can actually be installed:
                         a browser that has offered us an install prompt, or Telegram on a
                         client new enough to add a Mini App to the home screen. Hidden
                         everywhere else, and once it is already installed. --}}
                    <button type="button" data-install-app hidden><span><svg class="side-ico" viewBox="0 0 512 512" width="18" height="18" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" d="M320 336h76c55 0 100-21.21 100-75.6s-53-73.47-96-75.6C391.11 99.74 329 48 256 48c-69 0-113.44 45.79-128 91.2-60 5.7-112 35.88-112 98.4S70 336 136 336h56M192 400.1l64 63.9 64-63.9M256 224v224.03"/></svg></span>{{ __('Install app') }}</button>
                </nav>

                {{-- Language is a first-class control, not a buried setting: most of
                     this audience reads Amharic more comfortably than English. --}}
                <div class="player-lang" role="group" aria-label="{{ __('Language') }}">
                    @foreach (\App\Http\Middleware\SetLocale::supported() as $code => $language)
                        <a class="{{ app()->getLocale() === $code ? 'active' : '' }}"
                           href="{{ route('language.switch', $code) }}"
                           lang="{{ $code }}"
                           aria-current="{{ app()->getLocale() === $code ? 'true' : 'false' }}">{{ $language['name'] }}</a>
                    @endforeach
                </div>

                <div class="player-sidebar-note">
                    <span class="status-dot"></span>
                    @auth
                        <p><strong>{{ __('ETB wallet active') }}</strong><small>{{ __('Withdrawals are processed by your assigned agent') }}</small></p>
                    @else
                        <p><strong>{{ __('Sign in to bet') }}</strong><small>{{ __('Odds are open to browse — a bet needs your ETB wallet') }}</small></p>
                    @endauth
                </div>

                @auth
                    <form class="player-sidebar-logout" method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"><span><svg class="side-ico" viewBox="0 0 512 512" width="16" height="16" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path d="M304 336v40a40 40 0 01-40 40H104a40 40 0 01-40-40V136a40 40 0 0140-40h152c22.09 0 48 17.91 48 40v40M368 336l80-80-80-80M176 256h256" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="32"/></svg></span>{{ __('Log out') }}</button>
                    </form>
                @else
                    <div class="player-sidebar-logout">
                        <a class="player-deposit-btn" href="{{ route('login') }}">{{ __('Sign in') }}</a>
                    </div>
                @endauth
            </aside>
            <button class="player-sidebar-overlay" type="button" data-player-sidebar-overlay aria-label="Close player navigation"></button>

            <div class="player-content-column">
                @if (session('status'))<div class="flash flash-success">{{ session('status') }}</div>@endif
                {{-- Pages that flash `error` mostly print it themselves — the Shamo page
                     does. This is the backstop for the ones that do not, so a refusal
                     raised from anywhere is never silently dropped. --}}
                @if (session('error'))<div class="flash flash-error">{{ session('error') }}</div>@endif
                @if ($errors->any())<div class="flash flash-error">{{ $errors->first() }}</div>@endif
                @auth
                    @if (auth()->user()->hasPendingEmailVerification() && \App\Support\Mailer::configured())
                        <div class="verify-banner" role="status">
                            <p>{{ auth()->user()->needsEmailVerification() ? __('Confirm your email address to unlock withdrawals.') : __('Please confirm your email address.') }} {{ __('We sent a link to') }} {{ auth()->user()->email }}.</p>
                            <form method="POST" action="{{ route('verification.send') }}">@csrf<button type="submit">{{ __('Resend link') }}</button></form>
                        </div>
                    @endif
                @endauth
                <main class="player-stage">@yield('content')</main>
                <footer class="site-footer player-footer">
                    <div><a class="brand footer-brand" href="{{ url('/') }}"><img class="brand-logo" src="{{ asset('logo.png') }}" alt="Vamoseone logo" width="816" height="306" fetchpriority="high" decoding="async"></a><p>{{ __('Sports betting and casino games with an Ethiopian Birr wallet.') }}</p></div>
                    <div><h3>{{ __('Explore') }}</h3><a href="{{ route('home') }}">{{ __('Game lobby') }}</a><a href="{{ route('promotions') }}">{{ __('Promotions') }}</a><a href="{{ auth()->check() ? route('player.wallet') : route('login') }}">{{ __('Birr wallet') }}</a></div>
                    <div><h3>{{ __('Player care') }}</h3><a href="{{ route('responsible') }}">{{ __('Responsible play') }}</a><a href="{{ route('about') }}">{{ __('About Vamoseone') }}</a><span>{{ __('18+ only') }}</span></div>
                    <div class="footer-note"><span class="status-dot"></span><p>{{ __('Play responsibly') }}</p><small>&copy; {{ date('Y') }} Vamoseone</small></div>
                </footer>
            </div>

            <nav class="player-mobile-nav" aria-label="Mobile navigation">
                <a class="{{ request()->routeIs('sportsbook') ? 'active' : '' }}" href="{{ route('sportsbook') }}">
                    <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><circle cx="256" cy="256" r="192" fill="none" stroke-linecap="round" stroke-miterlimit="10" stroke-width="32"></circle><path fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" d="m256 175.15-76.09 63.83L200 320h112l20.09-81.02L256 175.15zm76.09 63.83 52.87-22.4 25.78-73.26M447 269.97l-62.04-53.39m-205.05 22.4-52.87-22.4-25.78-73.26M65 269.97l62.04-53.39M256 175.15v-57.57l64-42.64m-128-.01 64 42.65M312 320l28 48-28 71m98.74-71H342m-142-48-28 48 28.37 71.5M101.63 368H172"></path></svg>
                    <strong>{{ __('Sports') }}</strong>
                </a>
                <a class="{{ request()->routeIs('home', 'games.*') ? 'active' : '' }}" href="{{ route('home') }}">
                    <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path fill="none" stroke-miterlimit="10" stroke-width="32" d="M467.51 248.83c-18.4-83.18-45.69-136.24-89.43-149.17A91.5 91.5 0 0 0 352 96c-26.89 0-48.11 16-96 16s-69.15-16-96-16a99.09 99.09 0 0 0-27.2 3.66C89 112.59 61.94 165.7 43.33 248.83c-19 84.91-15.56 152 21.58 164.88 26 9 49.25-9.61 71.27-37 25-31.2 55.79-40.8 119.82-40.8s93.62 9.6 118.66 40.8c22 27.41 46.11 45.79 71.42 37.16 41.02-14.01 40.44-79.13 21.43-165.04z"></path><circle cx="292" cy="224" r="20"></circle><path d="M336 288a20 20 0 1 1 20-19.95A20 20 0 0 1 336 288z"></path><circle cx="336" cy="180" r="20"></circle><circle cx="380" cy="224" r="20"></circle><path fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" d="M160 176v96m48-48h-96"></path></svg>
                    <strong>{{ __('Casino') }}</strong>
                </a>
                <a class="{{ request()->routeIs('player.wallet') ? 'active' : '' }}" href="{{ auth()->check() ? route('player.wallet') : route('login') }}">
                    <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><rect width="416" height="288" x="48" y="144" fill="none" stroke-linejoin="round" stroke-width="32" rx="48" ry="48"></rect><path fill="none" stroke-linejoin="round" stroke-width="32" d="M411.36 144v-30A50 50 0 0 0 352 64.9L88.64 109.85A50 50 0 0 0 48 159v49"></path><path d="M368 320a32 32 0 1 1 32-32 32 32 0 0 1-32 32z"></path></svg>
                    <strong>{{ __('Wallet') }}</strong>
                </a>
                <a class="{{ request()->routeIs('promotions', 'deposit-bonus') ? 'active' : '' }}" href="{{ route('promotions') }}">
                    <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path fill="none" stroke-linecap="round" stroke-miterlimit="10" stroke-width="32" d="M256 104v56h56a56 56 0 1 0-56-56zm0 0v56h-56a56 56 0 1 1 56-56z"></path><rect width="384" height="112" x="64" y="160" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" rx="32" ry="32"></rect><path fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" d="M416 272v144a48 48 0 0 1-48 48H144a48 48 0 0 1-48-48V272m160-112v304"></path></svg>
                    <strong>{{ __('Promos') }}</strong>
                </a>
                <a href="{{ \App\Models\PlatformSetting::telegramSupportUrl() }}" target="_blank" rel="noopener noreferrer">
                    <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path fill="none" stroke-linecap="round" stroke-miterlimit="10" stroke-width="32" d="M87.49 380c1.19-4.38-1.44-10.47-3.95-14.86a44.86 44.86 0 0 0-2.54-3.8 199.81 199.81 0 0 1-33-110C47.65 139.09 140.73 48 255.83 48 356.21 48 440 117.54 459.58 209.85a199 199 0 0 1 4.42 41.64c0 112.41-89.49 204.93-204.59 204.93-18.3 0-43-4.6-56.47-8.37s-26.92-8.77-30.39-10.11a31.09 31.09 0 0 0-11.12-2.07 30.71 30.71 0 0 0-12.09 2.43l-67.83 24.48a16 16 0 0 1-4.67 1.22 9.6 9.6 0 0 1-9.57-9.74 15.85 15.85 0 0 1 .6-3.29z"></path></svg>
                    <strong>{{ __('Chat') }}</strong>
                </a>
                <button type="button" data-player-menu-toggle>
                    <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><circle cx="256" cy="256" r="48"></circle><circle cx="416" cy="256" r="48"></circle><circle cx="96" cy="256" r="48"></circle></svg>
                    <strong>{{ __('More') }}</strong>
                </button>
            </nav>
        </div>
    @else
        <header class="site-header">
            <a class="brand" href="{{ url('/') }}" aria-label="Vamoseone home"><img class="brand-logo" src="{{ asset('logo.png') }}" alt="Vamoseone logo" width="816" height="306" fetchpriority="high" decoding="async"></a>
            <button class="menu-toggle" type="button" data-menu-toggle aria-label="Toggle navigation">&#9776;</button>
            <nav class="main-nav" data-menu>
                <a class="{{ request()->routeIs('sportsbook') ? 'active' : '' }}" href="{{ route('sportsbook') }}">{{ __('Sports') }}</a>
                <a class="{{ request()->routeIs('home') ? 'active' : '' }}" href="{{ route('home') }}">{{ __('Casino') }}</a>
                <a class="{{ request()->routeIs('promotions') ? 'active' : '' }}" href="{{ route('promotions') }}">{{ __('Promotions') }}</a>
                <a href="{{ route('responsible') }}">{{ __('Responsible play') }}</a>
            </nav>
            <div class="header-actions">
                {{-- Guests never see the sidebar, so the switcher has to live here too,
                     or an Amharic speaker whose browser reports English is stuck. --}}
                <div class="player-lang header-lang" role="group" aria-label="{{ __('Language') }}">
                    @foreach (\App\Http\Middleware\SetLocale::supported() as $code => $language)
                        <a class="{{ app()->getLocale() === $code ? 'active' : '' }}"
                           href="{{ route('language.switch', $code) }}"
                           lang="{{ $code }}">{{ $language['name'] }}</a>
                    @endforeach
                </div>
                <a class="button button-ghost" href="{{ route('login') }}">{{ __('Log in') }}</a>
                {{-- Phones get the short label; the long one is for desktop. --}}
                <a class="button button-primary" href="{{ route('register') }}"><span class="label-long">{{ __('Create account') }}</span><span class="label-short">{{ __('Sign up') }}</span></a>
            </div>
        </header>
        @if (session('status'))<div class="flash flash-success">{{ session('status') }}</div>@endif
        @if (session('error'))<div class="flash flash-error">{{ session('error') }}</div>@endif
        @if ($errors->any())<div class="flash flash-error">{{ $errors->first() }}</div>@endif
        <main>@yield('content')</main>
        <footer class="site-footer">
            <div><a class="brand footer-brand" href="{{ url('/') }}"><img class="brand-logo" src="{{ asset('logo.png') }}" alt="Vamoseone logo" width="816" height="306" fetchpriority="high" decoding="async"></a><p>Original entertainment built for Ethiopia with Birr-denominated play.</p></div>
            <div><h3>{{ __('Explore') }}</h3><a href="{{ route('home') }}">{{ __('Game lobby') }}</a><a href="{{ route('promotions') }}">{{ __('Promotions') }}</a><a href="{{ route('about') }}">{{ __('About Vamoseone') }}</a></div>
            <div><h3>{{ __('Player care') }}</h3><a href="{{ route('responsible') }}">{{ __('Responsible play') }}</a><a class="footer-telegram" href="{{ \App\Models\PlatformSetting::telegramSupportUrl() }}" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 240 240" width="18" height="18" aria-hidden="true"><circle cx="120" cy="120" r="120" fill="#29a9eb"></circle><path fill="#fff" d="m53 122 130-50c6-2 11 1 9 10l-22 104c-2 8-7 10-14 6l-38-28-18 17c-2 2-4 4-8 4l3-40 73-66c3-3-1-4-4-2l-90 57-39-12c-8-3-8-9 2-12z"></path></svg><span>{{ __('Chat with support') }}</span></a><span>{{ __('18+ only') }}</span></div>
            <div class="footer-note"><span class="status-dot"></span><p>{{ __('Play responsibly') }}</p><small>&copy; {{ date('Y') }} Vamoseone</small></div>
        </footer>

        <nav class="player-mobile-nav" aria-label="Mobile navigation">
            <a class="{{ request()->routeIs('sportsbook') ? 'active' : '' }}" href="{{ route('sportsbook') }}">
                    <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><circle cx="256" cy="256" r="192" fill="none" stroke-linecap="round" stroke-miterlimit="10" stroke-width="32"></circle><path fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" d="m256 175.15-76.09 63.83L200 320h112l20.09-81.02L256 175.15zm76.09 63.83 52.87-22.4 25.78-73.26M447 269.97l-62.04-53.39m-205.05 22.4-52.87-22.4-25.78-73.26M65 269.97l62.04-53.39M256 175.15v-57.57l64-42.64m-128-.01 64 42.65M312 320l28 48-28 71m98.74-71H342m-142-48-28 48 28.37 71.5M101.63 368H172"></path></svg>
                    <strong>{{ __('Sports') }}</strong>
                </a>
            <a class="{{ request()->routeIs('home', 'games.*') ? 'active' : '' }}" href="{{ route('home') }}">
                <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path fill="none" stroke-miterlimit="10" stroke-width="32" d="M467.51 248.83c-18.4-83.18-45.69-136.24-89.43-149.17A91.5 91.5 0 0 0 352 96c-26.89 0-48.11 16-96 16s-69.15-16-96-16a99.09 99.09 0 0 0-27.2 3.66C89 112.59 61.94 165.7 43.33 248.83c-19 84.91-15.56 152 21.58 164.88 26 9 49.25-9.61 71.27-37 25-31.2 55.79-40.8 119.82-40.8s93.62 9.6 118.66 40.8c22 27.41 46.11 45.79 71.42 37.16 41.02-14.01 40.44-79.13 21.43-165.04z"></path><circle cx="292" cy="224" r="20"></circle><path d="M336 288a20 20 0 1 1 20-19.95A20 20 0 0 1 336 288z"></path><circle cx="336" cy="180" r="20"></circle><circle cx="380" cy="224" r="20"></circle><path fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" d="M160 176v96m48-48h-96"></path></svg>
                <strong>{{ __('Casino') }}</strong>
            </a>
            <a class="{{ request()->routeIs('player.wallet') ? 'active' : '' }}" href="{{ route('login') }}">
                <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><rect width="416" height="288" x="48" y="144" fill="none" stroke-linejoin="round" stroke-width="32" rx="48" ry="48"></rect><path fill="none" stroke-linejoin="round" stroke-width="32" d="M411.36 144v-30A50 50 0 0 0 352 64.9L88.64 109.85A50 50 0 0 0 48 159v49"></path><path d="M368 320a32 32 0 1 1 32-32 32 32 0 0 1-32 32z"></path></svg>
                <strong>{{ __('Wallet') }}</strong>
            </a>
            <a class="{{ request()->routeIs('promotions', 'deposit-bonus') ? 'active' : '' }}" href="{{ route('promotions') }}">
                <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path fill="none" stroke-linecap="round" stroke-miterlimit="10" stroke-width="32" d="M256 104v56h56a56 56 0 1 0-56-56zm0 0v56h-56a56 56 0 1 1 56-56z"></path><rect width="384" height="112" x="64" y="160" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" rx="32" ry="32"></rect><path fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="32" d="M416 272v144a48 48 0 0 1-48 48H144a48 48 0 0 1-48-48V272m160-112v304"></path></svg>
                <strong>{{ __('Promos') }}</strong>
            </a>
            <a href="{{ \App\Models\PlatformSetting::telegramSupportUrl() }}" target="_blank" rel="noopener noreferrer">
                <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><path fill="none" stroke-linecap="round" stroke-miterlimit="10" stroke-width="32" d="M87.49 380c1.19-4.38-1.44-10.47-3.95-14.86a44.86 44.86 0 0 0-2.54-3.8 199.81 199.81 0 0 1-33-110C47.65 139.09 140.73 48 255.83 48 356.21 48 440 117.54 459.58 209.85a199 199 0 0 1 4.42 41.64c0 112.41-89.49 204.93-204.59 204.93-18.3 0-43-4.6-56.47-8.37s-26.92-8.77-30.39-10.11a31.09 31.09 0 0 0-11.12-2.07 30.71 30.71 0 0 0-12.09 2.43l-67.83 24.48a16 16 0 0 1-4.67 1.22 9.6 9.6 0 0 1-9.57-9.74 15.85 15.85 0 0 1 .6-3.29z"></path></svg>
                <strong>{{ __('Chat') }}</strong>
            </a>
            <button type="button" data-menu-toggle>
                <svg class="nav-icon" viewBox="0 0 512 512" width="19" height="19" stroke="currentColor" fill="currentColor" stroke-width="0" aria-hidden="true"><circle cx="256" cy="256" r="48"></circle><circle cx="416" cy="256" r="48"></circle><circle cx="96" cy="256" r="48"></circle></svg>
                <strong>{{ __('More') }}</strong>
            </button>
        </nav>
    @endif

    @auth
    <script>
        /*
         * Keep the topbar balance honest while the page stays open.
         *
         * A sportsbook stake never passes through this page — WinHouse settles it
         * against the wallet API server-to-server — so without this the pill shows
         * the pre-bet figure until a reload, and a player who cannot see his stake
         * leave the balance places the bet again.
         *
         * Polls only while the tab is actually visible, and only fast on the
         * sportsbook; everywhere else it refreshes when the window regains focus,
         * which is enough for casino play (that path re-renders the page anyway).
         */
        (function () {
            var FAST = {{ request()->routeIs('sportsbook') ? 5000 : 0 }};
            var pill = document.querySelector('[data-balance-pill]');
            var full = document.querySelector('[data-balance-formatted]');
            if (!pill && !full) return;

            var last = pill ? pill.textContent.trim() : '';
            var inFlight = false;
            var timer = null;

            function apply(data) {
                if (pill && typeof data.pill === 'string' && data.pill !== pill.textContent.trim()) {
                    pill.textContent = data.pill;
                    // A number that changes without a page change is easy to miss.
                    pill.classList.remove('balance-bump');
                    void pill.offsetWidth;          // restart the animation
                    pill.classList.add('balance-bump');
                    last = data.pill;
                }
                if (full && typeof data.formatted === 'string') {
                    full.textContent = data.formatted;
                }
            }

            function refresh() {
                if (inFlight || document.hidden) return;
                inFlight = true;
                fetch('{{ route('player.balance') }}', {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) { if (d) apply(d); })
                    .catch(function () { /* a dropped poll is not worth surfacing */ })
                    .finally(function () { inFlight = false; });
            }

            function schedule() {
                if (timer) clearInterval(timer);
                if (FAST > 0 && !document.hidden) timer = setInterval(refresh, FAST);
            }

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) refresh();
                schedule();
            });
            window.addEventListener('focus', refresh);
            schedule();
        })();
    </script>
    @endauth

    <script src="/js/lucy-play.js?v={{ @filemtime(public_path('js/lucy-play.js')) ?: time() }}" defer></script>
    <script src="/js/lobby-rails.js?v={{ @filemtime(public_path('js/lobby-rails.js')) ?: time() }}" defer></script>
    <script src="/js/lobby-fx.js?v={{ @filemtime(public_path('js/lobby-fx.js')) ?: time() }}" defer></script>
    <script src="/js/lobby-hero.js?v={{ @filemtime(public_path('js/lobby-hero.js')) ?: time() }}" defer></script>

    {{-- Installability: keep the app on the home screen, in a browser or in Telegram. --}}
    <script>
    (function () {
        /* The service worker is what makes a browser offer "Install app" at all. It
           caches versioned static files only — never a page, never a balance. */
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/app-sw.js').catch(function () {
                    /* Registration fails on http:// and in private windows. The site
                       works fine without it; only the install prompt is lost. */
                });
            });
        }

        var button = document.querySelector('[data-install-app]');
        if (!button) return;

        /* telegram-web-app.js is loaded on every page, and outside Telegram it still
           hands back a WebApp object with addToHomeScreen on it. Testing for the
           method alone therefore says "yes" in an ordinary browser, where calling it
           does nothing. initData and platform are only populated by a real Telegram
           host, which is the same signal the auto-login above trusts. */
        var webApp = window.Telegram && window.Telegram.WebApp;
        var tg = (webApp && webApp.initData && webApp.platform && webApp.platform !== 'unknown')
            ? webApp
            : null;
        var prompt = null;

        function show() { button.hidden = false; }

        /* Already running as an installed app — there is nothing left to offer. */
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
            return;
        }

        /* Browser (Android Chrome, Edge, desktop): the browser tells us when the app
           meets the install criteria, and we save the event to fire on a real tap. */
        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            prompt = event;
            show();
        });

        window.addEventListener('appinstalled', function () { button.hidden = true; });

        /* iOS Safari never fires beforeinstallprompt — Add to Home Screen is only in
           the share sheet. Without this the button would never appear on iPhone at
           all, so it is shown there and the tap explains where to find it.
           Gated on the absence of onbeforeinstallprompt, which every Chromium
           browser has: sniffing the user agent instead matched Chrome on a Mac,
           whose UA also says "Safari". maxTouchPoints separates an iPad — which
           reports itself as a Mac since iOS 13 — from an actual desktop. */
        if (!('onbeforeinstallprompt' in window) && !tg) {
            var touchApple = /iP(hone|ad|od)/.test(navigator.platform)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            if (touchApple) show();
        }

        /* Telegram: Bot API 8.0 can put the Mini App on the device home screen, so it
           reopens straight into Telegram instead of being hunted for in a chat. Older
           clients expose neither method, and the button simply stays hidden. */
        if (tg && typeof tg.addToHomeScreen === 'function') {
            if (typeof tg.checkHomeScreenStatus === 'function') {
                try {
                    tg.checkHomeScreenStatus(function (status) {
                        if (status !== 'added') show();
                    });
                } catch (e) { show(); }
            } else {
                show();
            }
        }

        button.addEventListener('click', function () {
            if (tg && typeof tg.addToHomeScreen === 'function') {
                try { tg.addToHomeScreen(); return; } catch (e) { /* fall through */ }
            }

            if (prompt) {
                prompt.prompt();
                prompt.userChoice.finally(function () {
                    prompt = null;
                    button.hidden = true;
                });
                return;
            }

            /* iOS Safari has no programmatic install; Add to Home Screen lives in the
               share sheet, so say where it is rather than doing nothing. */
            var message = @json(__('To install: open the share menu and choose "Add to Home Screen".'));
            if (tg && typeof tg.showAlert === 'function') { tg.showAlert(message); } else { window.alert(message); }
        });
    })();
    </script>
</body>
</html>
