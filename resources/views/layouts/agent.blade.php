<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Agent Dashboard') · Birr Games</title>
    <link rel="icon" href="/icons/icon-32.png" sizes="32x32" type="image/png">
    <link rel="icon" href="/icons/icon-16.png" sizes="16x16" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/agent-portal.css?v={{ @filemtime(public_path('css/agent-portal.css')) ?: time() }}">
    {{-- Shows what is being typed into a password box. A stored password is a
         bcrypt hash and cannot be turned back into text, so this is the only
         "view password" there can be. --}}
    <link rel="stylesheet" href="/css/password-reveal.css?v={{ @filemtime(public_path('css/password-reveal.css')) ?: time() }}">
    <script defer src="/js/password-reveal.js?v={{ @filemtime(public_path('js/password-reveal.js')) ?: time() }}"></script>
    {{-- The confirmation on every destructive form. --}}
    <script defer src="/js/confirm-action.js?v={{ @filemtime(public_path('js/confirm-action.js')) ?: time() }}"></script>
</head>
<body class="agent-body">
@php($currentAgent = auth('agent')->user())
<div class="agent-shell" data-agent-shell>
    <aside class="agent-sidebar">
        <div class="agent-brand"><img class="agent-brand-logo" src="/logo.png" alt="Birr Games logo"></div>
        <nav class="agent-nav">
            <p>Agent dashboard</p>
            <a class="{{ request()->routeIs('agent.dashboard') ? 'active' : '' }}" href="{{ route('agent.dashboard') }}"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg> Stats</a>
            <a class="{{ request()->routeIs('agent.bank-info*') ? 'active' : '' }}" href="{{ route('agent.bank-info') }}"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/><circle cx="17.5" cy="14.5" r="1.3"/></svg> Bank Info</a>
            <a class="{{ request()->routeIs('agent.deposit-orders') ? 'active' : '' }}" href="{{ route('agent.deposit-orders') }}"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg> Deposit Orders</a>
            <a class="{{ request()->routeIs('agent.customers') ? 'active' : '' }}" href="{{ route('agent.customers') }}"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>My Customers</a>
            <a class="{{ request()->routeIs('agent.reports') ? 'active' : '' }}" href="{{ route('agent.reports') }}"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="m7 15 3.5-4 3 2.5L18 8"/></svg> Reports</a>
            <a class="{{ request()->routeIs('agent.withdrawals*') ? 'active' : '' }}" href="{{ route('agent.withdrawals') }}"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg> Withdrawal</a>
            <a class="{{ request()->routeIs('agent.direct-deposit*') ? 'active' : '' }}" href="{{ route('agent.direct-deposit') }}"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg> Direct Deposit</a>
            <a class="{{ request()->routeIs('agent.credit-transfer*') ? 'active' : '' }}" href="{{ route('agent.credit-transfer') }}"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4 3 8l4 4"/><path d="M3 8h13a4 4 0 0 1 0 8h-1"/><path d="m17 20 4-4-4-4"/></svg> Credit Transfer</a>
            <a class="{{ request()->routeIs('agent.profile*') ? 'active' : '' }}" href="{{ route('agent.profile') }}"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg> Profile</a>
        </nav>
        <div class="agent-profile-chip">
            <span>{{ strtoupper(substr($currentAgent->first_name, 0, 1)) }}</span>
            <div><strong>{{ $currentAgent->first_name }} {{ $currentAgent->last_name }}</strong><small>{{ $currentAgent->phone }}</small></div>
            {{-- The only theme control used to live in .agent-mobile-header, which is
                 display:none above 900px, so a desktop agent was stuck in the dark
                 default with nothing to press. The sidebar is on screen at every width. --}}
            <button class="agent-theme-button" type="button" data-theme-toggle title="Switch colour theme" aria-label="Switch colour theme"><span class="ag-ico-sun"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></span><span class="ag-ico-moon"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg></span></button>
            <form method="POST" action="{{ route('agent.logout') }}">@csrf<button title="Log out" aria-label="Log out"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg></button></form>
        </div>
    </aside>
    <div class="agent-main-wrap">
        <header class="agent-mobile-header">
            <button type="button" data-agent-menu aria-label="Toggle navigation"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
            <strong>Agent Dashboard</strong>
            <button type="button" data-theme-toggle aria-label="Toggle colour theme" title="Toggle colour theme"><span class="ag-ico-sun"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></span><span class="ag-ico-moon"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg></span></button>
        </header>
        <main class="agent-main">
            @if(session('agent_status'))<div class="agent-alert success">{{ session('agent_status') }}</div>@endif
            {{-- The portal writes `agent_status` in most places and the conventional
                 `status` in a couple more; only the first was ever rendered, so those
                 confirmations happened silently. Both are printed, and `error` with
                 them, so a refusal from anywhere reaches the agent. --}}
            @if(session('status'))<div class="agent-alert success">{{ session('status') }}</div>@endif
            @if(session('error'))<div class="agent-alert error">{{ session('error') }}</div>@endif
            @if($errors->any())<div class="agent-alert error"><strong>Please check the form.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
</div>
<script>
(() => {
    const shell = document.querySelector('[data-agent-shell]');
    const agentMenu = document.querySelector('[data-agent-menu]');
    const setAgentMenu = (open) => {
        shell?.classList.toggle('menu-open', open);
        agentMenu?.setAttribute('aria-expanded', String(open));
    };
    agentMenu?.addEventListener('click', () => setAgentMenu(!shell?.classList.contains('menu-open')));
    // Tapping a destination should close the drawer, and Escape should too.
    shell?.querySelectorAll('.agent-nav a').forEach((link) => link.addEventListener('click', () => setAgentMenu(false)));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setAgentMenu(false);
    });
    const key = 'lucy-agent-theme';
    const apply = value => document.documentElement.dataset.agentTheme = value;
    apply(localStorage.getItem(key) || 'dark');
    document.querySelectorAll('[data-theme-toggle]').forEach(button => button.addEventListener('click', () => {
        const next = document.documentElement.dataset.agentTheme === 'dark' ? 'light' : 'dark';
        localStorage.setItem(key, next); apply(next);
    }));
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const uuid = () => crypto.randomUUID ? crypto.randomUUID() :
        'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
            const random = Math.random() * 16 | 0;
            return (character === 'x' ? random : (random & 0x3 | 0x8)).toString(16);
        });
    const protectedPaths = ['/agent/direct-deposit', '/agent/credit'];
    document.querySelectorAll('form').forEach(form => {
        const path = new URL(form.action, window.location.origin).pathname;
        if (!protectedPaths.includes(path)) return;
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
});
</script>
@stack('scripts')
<script src="/js/nav-progress.js?v={{ @filemtime(public_path('js/nav-progress.js')) ?: time() }}" defer></script>
</body>
</html>
