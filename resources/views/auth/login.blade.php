@extends('layouts.app')

@section('title', 'Log in — Vamoseone')

@if ($turnstileSiteKey !== '')
    @push('head')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif

@section('content')
<div class="auth-modal-wrapper">
    <div class="auth-card auth-modal-card">
        <header class="auth-card-header">
            <h2>Log in</h2>
            <a class="auth-close-btn" href="{{ route('home') }}" aria-label="Close">&times;</a>
        </header>

        @if ($errors->any())
            <div class="form-errors">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="stacked-form">
            @csrf
            <label>Phone Number or Username<input type="text" name="phone" value="{{ old('phone') }}" placeholder="Enter your phone number" maxlength="190" autocomplete="username" required autofocus></label>
            <label>Password<input type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required></label>
            <label class="check-row"><input type="checkbox" name="remember" value="1"><span>Keep me signed in</span></label>
            @if ($turnstileSiteKey !== '')
                <div class="auth-turnstile">
                    <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="dark" data-action="login" data-size="flexible"></div>
                </div>
            @endif
            <button class="button button-primary button-wide auth-submit-btn" type="submit">Log In</button>
        </form>

        <p class="auth-switch">New here? <a href="{{ route('register') }}">Create an account</a></p>
    </div>
</div>
@endsection
