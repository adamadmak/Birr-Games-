@extends('layouts.app')

@section('title', __('Log in') . ' — Vamoseone')

@if ($turnstileSiteKey !== '')
    @push('head')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif

@section('content')
<div class="auth-modal-wrapper">
    <div class="auth-card auth-modal-card">
        <header class="auth-card-header">
            <h2>{{ __('Log in') }}</h2>
            <a class="auth-close-btn" href="{{ route('home') }}" aria-label="{{ __('Close') }}">&times;</a>
        </header>

        @if ($errors->any())
            <div class="form-errors">{{ $errors->first() }}</div>
        @endif

        @if (session('status'))
            <div class="form-success">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="stacked-form">
            @csrf
            <label>{{ __('Phone Number or Username') }}<input type="text" name="phone" value="{{ old('phone') }}" placeholder="{{ __('Enter your phone number') }}" maxlength="190" autocomplete="username" required autofocus></label>
            <label>{{ __('Password') }}<input type="password" name="password" placeholder="{{ __('Enter your password') }}" autocomplete="current-password" required></label>
            <div class="auth-row">
                <label class="check-row"><input type="checkbox" name="remember" value="1"><span>{{ __('Keep me signed in') }}</span></label>
                <a class="auth-forgot" href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a>
            </div>
            @if ($turnstileSiteKey !== '')
                <div class="auth-turnstile">
                    <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="dark" data-action="login" data-size="flexible"></div>
                </div>
            @endif
            <button class="button button-primary button-wide auth-submit-btn" type="submit">{{ __('Log In') }}</button>
        </form>

        <p class="auth-switch">{{ __('New here?') }} <a href="{{ route('register') }}">{{ __('Create an account') }}</a></p>
    </div>
</div>
@endsection
