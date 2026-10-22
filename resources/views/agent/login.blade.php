<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agent Login · Lucy Play</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/agent-portal.css?v={{ @filemtime(public_path('css/agent-portal.css')) ?: time() }}">
    {{-- Shows what is being typed into a password box. A stored password is a
         bcrypt hash and cannot be turned back into text, so this is the only
         "view password" there can be. --}}
    <link rel="stylesheet" href="/css/password-reveal.css?v={{ @filemtime(public_path('css/password-reveal.css')) ?: time() }}">
    <script defer src="/js/password-reveal.js?v={{ @filemtime(public_path('js/password-reveal.js')) ?: time() }}"></script>
</head>
<body class="agent-login-body">
<main class="agent-login-page">
    <div class="agent-login-heading"><span>♢</span><h1>Agent Dashboard</h1></div>
    <section class="agent-login-card">
        <h2>Welcome Back</h2><p>Enter your credentials to manage your dashboard</p>
        @if($errors->any())<div class="agent-login-error">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('agent.login.store') }}">@csrf
            <label>Phone Number<div class="agent-input"><span>⌕</span><input name="phone" value="{{ old('phone') }}" autocomplete="username" placeholder="2519..." required autofocus></div></label>
            <label>Password<div class="agent-input"><span>♙</span><input type="password" name="password" autocomplete="current-password" placeholder="••••••••" required></div></label>
            <label class="agent-remember"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
            <button class="agent-primary" type="submit">Login <span>→</span></button>
        </form>
    </section>
    <p class="agent-support">Need help? <a href="mailto:support@lucyplay.local">Contact Support</a></p>
</main>
</body>
</html>
