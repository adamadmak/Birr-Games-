<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — Lucy Play</title>
    <link rel="icon" href="/icons/icon-32.png" sizes="32x32" type="image/png">
    <link rel="icon" href="/icons/icon-16.png" sizes="16x16" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&family=Bricolage+Grotesque:opsz,wght@12..96,400..800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&family=Bricolage+Grotesque:opsz,wght@12..96,400..800&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"></noscript>
    <link rel="stylesheet" href="/css/lucy-admin.css?v={{ @filemtime(public_path('css/lucy-admin.css')) ?: time() }}">
    {{-- Shows what is being typed into a password box. A stored password is a
         bcrypt hash and cannot be turned back into text, so this is the only
         "view password" there can be. --}}
    <link rel="stylesheet" href="/css/password-reveal.css?v={{ @filemtime(public_path('css/password-reveal.css')) ?: time() }}">
    <script defer src="/js/password-reveal.js?v={{ @filemtime(public_path('js/password-reveal.js')) ?: time() }}"></script>
    {{-- The confirmation on every destructive form. --}}
    <script defer src="/js/confirm-action.js?v={{ @filemtime(public_path('js/confirm-action.js')) ?: time() }}"></script>
    {{-- Per-page styling, so one screen can carry its own layout without every other screen sharing it. --}}
    @stack('styles')
    <script>
    /* Runs before first paint: applies the saved theme so the page never flashes
       the wrong palette. Dark is the default when nothing has been chosen. */
    (function () {
        try {
            var saved = localStorage.getItem('lucy-admin-theme');
            if (saved === 'light' || saved === 'dark') {
                document.documentElement.setAttribute('data-theme', saved);
            }
        } catch (e) { /* private mode: fall back to the dark default */ }
    })();
    </script>
</head>
<body class="admin-body">
    <aside class="admin-sidebar" data-admin-sidebar>
        <div class="admin-brand">
            <img class="admin-brand-logo" src="/logo.png" alt="Lucy Play logo">
            <div><strong>Lucy Play</strong><small>Operations</small></div>
            <button type="button" data-sidebar-toggle aria-label="Toggle navigation"><i class="admin-collapse-icon fa-solid fa-table-columns"></i><i class="admin-close-icon fa-solid fa-xmark"></i></button>
        </div>

        <button class="admin-quick-trigger" type="button" data-palette-open data-testid="palette-trigger">
            <i class="fa-solid fa-magnifying-glass"></i>
            <em>Search anything</em>
            <kbd>Ctrl K</kbd>
        </button>

        <nav class="admin-nav">
            <p>Overview</p>
            <a class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}" data-testid="nav-dashboard"><span><i class="fa-solid fa-chart-pie"></i></span><em>Dashboard</em></a>
            <a class="{{ request()->routeIs('admin.online-players') ? 'active' : '' }}" href="{{ route('admin.online-players') }}"><span><i class="fa-solid fa-users"></i></span><em>Players Online</em></a>
            @if(auth()->user()->hasAdminPermission('operations.view'))
                <a class="{{ request()->routeIs('admin.operations.*') ? 'active' : '' }}" href="{{ route('admin.operations.index') }}" data-testid="nav-operations"><span><i class="fa-solid fa-clipboard-check"></i></span><em>Operations center</em></a>
            @endif

            <p>Management</p>
            <a class="{{ request()->routeIs('admin.administrators.*') ? 'active' : '' }}" href="{{ route('admin.administrators.index') }}" data-testid="nav-administrators"><span><i class="fa-solid fa-user-shield"></i></span><em>Administrators</em></a>
            <a class="{{ request()->routeIs('admin.members.*') ? 'active' : '' }}" href="{{ route('admin.members.index') }}" data-testid="nav-members"><span><i class="fa-solid fa-users"></i></span><em>Members</em></a>
            @if(auth()->user()->hasAdminPermission('transactions.view'))
                <a class="{{ request()->routeIs('admin.players.*') ? 'active' : '' }}" href="{{ route('admin.players.report') }}" data-testid="nav-player-report"><span><i class="fa-solid fa-scale-balanced"></i></span><em>Player report</em></a>
            @endif
            <a href="{{ route('admin.module', 'promotion-banners') }}" data-testid="nav-promotion-banners"><span><i class="fa-solid fa-image"></i></span><em>Promotion banners</em></a>
            <a class="{{ request()->routeIs('admin.settings.*') ? 'active' : '' }}" href="{{ route('admin.settings.index') }}" data-testid="nav-configuration"><span><i class="fa-solid fa-gear"></i></span><em>Configuration</em></a>
            <a class="{{ request()->routeIs('admin.payment-gateways.*') ? 'active' : '' }}" href="{{ route('admin.payment-gateways.index') }}" data-testid="nav-payment-gateways"><span><i class="fa-solid fa-credit-card"></i></span><em>Payment gateways</em></a>
            <a class="{{ request()->routeIs('admin.transactions.*') ? 'active' : '' }}" href="{{ route('admin.transactions.index') }}" data-testid="nav-wallet-transactions"><span><i class="fa-solid fa-chart-simple"></i></span><em>Transactions</em></a>

            <p>Agents</p>
            <a class="{{ request()->routeIs('admin.agents.index') ? 'active' : '' }}" href="{{ route('admin.agents.index') }}" data-testid="nav-agent-list"><span><i class="fa-solid fa-list-ul"></i></span><em>Agent list</em></a>
            <a class="{{ request()->routeIs('admin.agents.bank-info*') ? 'active' : '' }}" href="{{ route('admin.agents.bank-info') }}" data-testid="nav-agent-bank-info"><span><i class="fa-solid fa-building-columns"></i></span><em>Agent bank info</em></a>
            <a class="{{ request()->routeIs('admin.agents.create') ? 'active' : '' }}" href="{{ route('admin.agents.create') }}" data-testid="nav-create-agent"><span><i class="fa-solid fa-user-plus"></i></span><em>Create agent</em></a>
            <a class="{{ request()->routeIs('admin.agents.withdrawals') ? 'active' : '' }}" href="{{ route('admin.agents.withdrawals') }}" data-testid="nav-agent-withdrawals"><span><i class="fa-solid fa-money-bill-transfer"></i></span><em>Withdrawal list</em></a>
            <a class="{{ request()->routeIs('admin.agents.orders') ? 'active' : '' }}" href="{{ route('admin.agents.orders') }}" data-testid="nav-agent-orders"><span><i class="fa-solid fa-receipt"></i></span><em>Orders list</em></a>
            <a class="{{ request()->routeIs('admin.agents.reports*') ? 'active' : '' }}" href="{{ route('admin.agents.reports') }}" data-testid="nav-agent-reports"><span><i class="fa-solid fa-chart-line"></i></span><em>Report transactions</em></a>

            <p>Casino</p>
            <a class="{{ request()->routeIs('admin.game-engine.*') ? 'active' : '' }}" href="{{ route('admin.game-engine.index') }}" data-testid="nav-casino-engine"><span><i class="fa-solid fa-dice"></i></span><em>Casino engine</em></a>
            <a class="{{ request()->routeIs('admin.casino.report') || request()->routeIs('admin.rounds.*') ? 'active' : '' }}" href="{{ route('admin.casino.report') }}" data-testid="nav-game-reports"><span><i class="fa-solid fa-chart-column"></i></span><em>Casino report</em></a>
            <a class="{{ request()->routeIs('admin.casino.transactions') ? 'active' : '' }}" href="{{ route('admin.casino.transactions') }}" data-testid="nav-casino-transactions"><span><i class="fa-solid fa-right-left"></i></span><em>Transactions</em></a>

            <p>Growth</p>
            <a class="{{ request()->routeIs('admin.promotions.*') ? 'active' : '' }}" href="{{ route('admin.promotions.index') }}" data-testid="nav-promotions"><span><i class="fa-solid fa-gift"></i></span><em>Promotions</em></a>
            <a class="{{ request()->routeIs('admin.wagering.*') ? 'active' : '' }}" href="{{ route('admin.wagering.index') }}" data-testid="nav-wagering"><span><i class="fa-solid fa-percent"></i></span><em>Wagering config</em></a>
            <a class="{{ request()->routeIs('admin.shamo.*') ? 'active' : '' }}" href="{{ route('admin.shamo.index') }}" data-testid="nav-shamo"><span><i class="fa-solid fa-bullseye"></i></span><em>Shamo campaigns</em></a>
            <a class="{{ request()->routeIs('admin.predictions.*') ? 'active' : '' }}" href="{{ route('admin.predictions.index') }}" data-testid="nav-predictions"><span><i class="fa-solid fa-futbol"></i></span><em>Prediction pools</em></a>
            <a href="{{ route('admin.module', 'loyalty-bonus') }}" data-testid="nav-loyalty-bonus"><span><i class="fa-solid fa-medal"></i></span><em>Loyalty bonus</em></a>
            <a href="{{ route('admin.module', 'challenges') }}" data-testid="nav-challenges"><span><i class="fa-solid fa-flag-checkered"></i></span><em>Challenges</em></a>
            <a href="{{ route('admin.module', 'tournaments') }}" data-testid="nav-tournaments"><span><i class="fa-solid fa-trophy"></i></span><em>Tournaments</em></a>

            <p>Automation</p>
            @php($telegramBot = app(\App\Services\TelegramBotService::class)->botUsername())
            {{-- Through the service, which reads the bot an operator actually
                 configured. Reading the raw config here meant a brand could set
                 its own bot and this link still pointed at the committed one. --}}
            @if ($telegramBot !== '')
                <a href="https://t.me/{{ $telegramBot }}" target="_blank" rel="noopener" data-testid="nav-telegram"><span><i class="fa-brands fa-telegram"></i></span><em>Telegram bot</em></a>
            @endif
            <a class="{{ request()->routeIs('admin.telegram.*') ? 'active' : '' }}" href="{{ route('admin.telegram.index') }}" data-testid="nav-broadcast-bot"><span><i class="fa-solid fa-tower-broadcast"></i></span><em>Broadcast bot</em></a>
        </nav>

        <div class="admin-profile">
            <span>{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button title="Log out" data-testid="logout-button"><i class="fa-solid fa-arrow-right-from-bracket"></i></button></form>
        </div>
    </aside>
    <button class="admin-sidebar-overlay" type="button" data-admin-sidebar-overlay aria-label="Close navigation"></button>

    <div class="admin-shell">
        <header class="admin-topbar">
            <button class="topbar-button admin-topbar-menu" type="button" data-sidebar-toggle aria-label="Open navigation" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
            <div class="admin-topbar-title">
                <small>Lucy Play Operations</small>
                <strong>@yield('title', 'Dashboard')</strong>
            </div>
            <div class="admin-topbar-actions">
                <button class="topbar-button topbar-search" type="button" data-palette-open aria-label="Search the admin">
                    <i class="fa-solid fa-magnifying-glass"></i><span>Search</span><kbd>⌘K</kbd>
                </button>
                <button class="topbar-button theme-toggle" type="button" data-theme-toggle aria-label="Switch colour theme" title="Switch colour theme">
                    <i class="icon-light fa-solid fa-sun"></i><i class="icon-dark fa-solid fa-moon"></i>
                </button>
                <a class="topbar-button admin-topbar-site" href="{{ route('home') }}" target="_blank" rel="noopener" title="Open the player site">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i><em>View site</em>
                </a>
                <span class="admin-topbar-avatar" title="{{ auth()->user()->name }}">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
            </div>
        </header>

        <main class="admin-main">
            @if (session('admin_status'))<div class="admin-flash" data-testid="admin-flash" data-autodismiss><i class="fa-solid fa-circle-check"></i> {{ session('admin_status') }}<button type="button" data-flash-close aria-label="Dismiss"><i class="fa-solid fa-xmark"></i></button></div>@endif
            @if ($errors->any())<div class="admin-flash admin-flash-error" data-testid="admin-flash-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}<button type="button" data-flash-close aria-label="Dismiss"><i class="fa-solid fa-xmark"></i></button></div>@endif
            @yield('content')
        </main>
    </div>

    <div class="admin-palette" data-palette hidden>
        <button class="admin-palette-scrim" type="button" data-palette-close aria-label="Close search"></button>
        <div class="admin-palette-box" role="dialog" aria-modal="true" aria-label="Search the admin">
            <header>
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="Search members, agents, transactions or pages..." data-palette-input aria-label="Search query" autocomplete="off" spellcheck="false">
                <kbd>Esc</kbd>
            </header>
            <div class="admin-palette-results" data-palette-results role="listbox" aria-label="Search results"></div>
            <footer>
                <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
                <span><kbd>Enter</kbd> open</span>
                <span><kbd>Ctrl</kbd>+<kbd>K</kbd> anywhere</span>
            </footer>
        </div>
    </div>

    <script src="/js/nav-progress.js?v={{ @filemtime(public_path('js/nav-progress.js')) ?: time() }}" defer></script>
    <script src="/js/lucy-admin.js?v={{ @filemtime(public_path('js/lucy-admin.js')) ?: time() }}" defer></script>
    <script src="/js/lucy-admin-palette.js?v={{ @filemtime(public_path('js/lucy-admin-palette.js')) ?: time() }}" data-endpoint="{{ route('admin.quick-search') }}" defer></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const uuid = () => crypto.randomUUID ? crypto.randomUUID() :
            'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
                const random = Math.random() * 16 | 0;
                return (character === 'x' ? random : (random & 0x3 | 0x8)).toString(16);
            });
        const protectedPaths = [
            /^\/admin\/members\/\d+\/deposit$/,
            /^\/admin\/agents\/\d+\/balance$/,
        ];
        document.querySelectorAll('form').forEach(form => {
            const path = new URL(form.action, window.location.origin).pathname;
            if (!protectedPaths.some(pattern => pattern.test(path))) return;
            if (!form.querySelector('[name="idempotency_key"]')) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'idempotency_key';
                input.value = uuid();
                form.appendChild(input);
            }
            form.addEventListener('submit', () => {
                window.setTimeout(() => form.querySelectorAll('[type="submit"], button:not([type])')
                    .forEach(button => button.disabled = true), 0);
            });
        });
        document.querySelectorAll('form[action*="/password"] input[type="password"]')
            .forEach(input => input.minLength = Math.max(input.minLength, 10));
    });
    </script>
</body>
</html>
