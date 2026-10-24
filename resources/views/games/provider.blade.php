<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $game->title }} — Vamoseone</title>
    <script src="https://telegram.org/js/telegram-web-app.js?v=8.0"></script>
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { width:100%; height:100%; overflow:hidden; background:#000; color:#f8fafc; font-family:Inter,ui-sans-serif,system-ui,-apple-system,sans-serif; }

        .game-layout {
            display:flex;
            flex-direction:column;
            width:100vw;
            height:100vh;
            height:100dvh;
            background:#000;
        }

        /* ── Top Header Bar ── */
        .game-bar {
            height:48px;
            flex:0 0 48px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            /* 85px left offset to clear Telegram's native Close (X) button */
            /* 75px right offset to clear Telegram's native (v / :) buttons */
            padding:0 75px 0 85px;
            background:#0d131d;
            border-bottom:1px solid rgba(255,255,255,.1);
            z-index:100;
            user-select:none;
        }

        /* Left: Back button */
        .bar-back {
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:7px 16px;
            border:1px solid rgba(255,255,255,.18);
            border-radius:10px;
            background:rgba(255,255,255,.12);
            color:#fff;
            font:inherit;
            font-size:12px;
            font-weight:800;
            text-decoration:none;
            cursor:pointer;
            white-space:nowrap;
            transition:background .15s, transform .1s, border-color .15s;
            box-shadow:0 2px 8px rgba(0,0,0,.3);
        }
        .bar-back:hover, .bar-back:active { background:rgba(255,255,255,.22); border-color:rgba(255,255,255,.35); transform:scale(.97); }
        .bar-back svg { width:14px; height:14px; fill:none; stroke:currentColor; stroke-width:2.8; stroke-linecap:round; stroke-linejoin:round; }

        /* Right controls */
        .bar-actions {
            display:flex;
            align-items:center;
            gap:8px;
        }

        /* Icon buttons */
        .bar-icon-btn {
            width:34px; height:34px;
            display:grid; place-items:center;
            border:1px solid rgba(255,255,255,.1);
            border-radius:9px;
            background:rgba(255,255,255,.08);
            color:#e2e8f0;
            font-size:14px;
            cursor:pointer;
            transition:background .15s, color .15s;
        }
        .bar-icon-btn:hover { background:rgba(255,255,255,.16); color:#fff; }
        .bar-icon-btn svg { width:15px; height:15px; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .bar-icon-btn.active { color:#ff7180; border-color:rgba(255,113,128,.3); background:rgba(255,113,128,.1); }

        /* Green Deposit Button (Arada style) */
        .bar-deposit {
            padding:7px 16px;
            border:0;
            border-radius:9px;
            background:linear-gradient(135deg, #17e495, #02b571);
            color:#fff;
            font:inherit;
            font-size:12px;
            font-weight:800;
            text-decoration:none;
            cursor:pointer;
            box-shadow:0 3px 12px rgba(23,228,149,.35);
            transition:transform .15s, box-shadow .15s;
        }
        .bar-deposit:hover { transform:translateY(-1px); box-shadow:0 5px 16px rgba(23,228,149,.45); }

        /* ── Main Game Stage ── */
        .game-stage {
            flex:1 1 auto;
            width:100%;
            height:calc(100vh - 48px);
            height:calc(100dvh - 48px);
            position:relative;
            background:#000;
        }
        .game-stage iframe {
            display:block;
            width:100%;
            height:100%;
            border:0;
            background:#000;
        }

        /* Desktop view (wider padding) */
        @media(min-width:768px){
            .game-bar { padding:0 20px; }
        }

        /* Mobile adjustments */
        @media(max-width:480px){
            .game-bar { height:46px; padding:0 68px 0 82px; }
            .bar-back { padding:6px 12px; font-size:11px; }
            .bar-deposit { padding:6px 12px; font-size:11px; }
            .bar-actions { gap:5px; }
            .bar-icon-btn { width:32px; height:32px; }
        }
    </style>
</head>
<body>
    <div class="game-layout">
        <!-- Top Bar safely positioned between Telegram WebApp header overlays -->
        <header class="game-bar" id="gameBar">
            <a class="bar-back" href="{{ route('home') }}" target="_top" aria-label="Back to Lobby">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                <span>Back</span>
            </a>

            <div class="bar-actions">
                <button type="button" class="bar-icon-btn" id="favBtn" aria-label="Favorite game">
                    <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </button>
                <button type="button" class="bar-icon-btn" data-provider-reload aria-label="Reload game">
                    <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                </button>
                <button type="button" class="bar-icon-btn" data-provider-fullscreen aria-label="Enter fullscreen">
                    <svg viewBox="0 0 24 24"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                </button>
                <a class="bar-deposit" href="{{ route('player.wallet', ['tab' => 'deposit']) }}" target="_top">Deposit</a>
            </div>
        </header>

        <!-- Game Iframe Stage -->
        <main class="game-stage" data-provider-stage>
            <iframe
                data-provider-frame
                src="{{ $launchUrl }}"
                title="Play {{ $game->title }}"
                allow="autoplay; fullscreen; gamepad"
                allowfullscreen
                loading="eager"
                referrerpolicy="strict-origin-when-cross-origin"
            ></iframe>
        </main>
    </div>

    <script>
        /* Telegram WebApp Integration */
        var tg = window.Telegram && window.Telegram.WebApp;
        var inTelegram = !!(tg && tg.initData !== undefined && tg.platform && tg.platform !== 'unknown');

        /* Break out if accidentally loaded inside an iframe. Never inside Telegram: the
           mini app is already the top document there, and rewriting top.location closes it. */
        if (!inTelegram && window.top !== window.self) {
            try { window.top.location.href = window.location.href; } catch(e) {}
        }

        if (tg) {
            try { tg.ready(); } catch(e) {}
            try { tg.expand(); } catch(e) {}
            /* Games are full of drag and swipe gestures. Without these two calls a vertical
               swipe inside the game closes or minimises the mini app mid-round. */
            try { if (typeof tg.disableVerticalSwipes === 'function') tg.disableVerticalSwipes(); } catch(e) {}
            try { if (typeof tg.enableClosingConfirmation === 'function') tg.enableClosingConfirmation(); } catch(e) {}
            try { if (typeof tg.setHeaderColor === 'function') tg.setHeaderColor('#0d131d'); } catch(e) {}
            try { if (typeof tg.setBackgroundColor === 'function') tg.setBackgroundColor('#000000'); } catch(e) {}
            try { if (tg.BackButton) tg.BackButton.hide(); } catch(e) {}
            try { if (typeof tg.onEvent === 'function') tg.onEvent('viewportChanged', function () { try { tg.expand(); } catch(e) {} }); } catch(e) {}
        }

        /* Retire the provider session the moment the player leaves this screen, so the
           provider can no longer settle anything against their wallet. */
        var sessionClosed = false;
        function closeProviderSession() {
            if (sessionClosed) return;
            sessionClosed = true;
            var body = '_token=' + encodeURIComponent(@json(csrf_token()));
            var url = @json(route('games.provider.close-session'));
            try {
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(url, new Blob([body], { type: 'application/x-www-form-urlencoded' }));
                    return;
                }
            } catch (e) {}
            try {
                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                    credentials: 'same-origin',
                    keepalive: true
                });
            } catch (e) {}
        }

        document.querySelector('.bar-back').addEventListener('click', closeProviderSession);
        window.addEventListener('pagehide', function (event) {
            /* persisted === true means the page went to the back/forward cache (the player
               just backgrounded Telegram), so the session must stay open. */
            if (!event.persisted) closeProviderSession();
        });

        /* Reload */
        document.querySelector('[data-provider-reload]').addEventListener('click', function () {
            sessionClosed = false;
            document.querySelector('[data-provider-frame]').src = @json($launchUrl);
        });

        /* Fullscreen */
        document.querySelector('[data-provider-fullscreen]').addEventListener('click', function () {
            var stage = document.querySelector('[data-provider-stage]');
            if (document.fullscreenElement) document.exitFullscreen();
            else if (stage.requestFullscreen) stage.requestFullscreen();
        });

        /* Favorite toggle */
        var favBtn = document.getElementById('favBtn');
        var favKey = 'lucy-favorite-{{ $game->slug }}';
        if (localStorage.getItem(favKey) === '1') favBtn.classList.add('active');
        favBtn.addEventListener('click', function () {
            var active = favBtn.classList.toggle('active');
            localStorage.setItem(favKey, active ? '1' : '0');
        });
    </script>
</body>
</html>
