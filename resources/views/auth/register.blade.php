@extends('layouts.app')

@section('title', 'Create an account — Vamoseone')

@if ($turnstileSiteKey !== '')
    @push('head')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif

@section('content')
<div class="auth-modal-wrapper">
    <div class="auth-card auth-modal-card">
        <header class="auth-card-header">
            <h2>Create an account</h2>
            <a class="auth-close-btn" href="{{ route('home') }}" aria-label="Close">&times;</a>
        </header>

        @if ($errors->any())
            <div class="form-errors">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('register') }}" class="stacked-form" autocomplete="on">
            @csrf
            {{-- Issued when this page was rendered; see App\Services\RegistrationGuard. --}}
            <input type="hidden" name="{{ \App\Services\RegistrationGuard::TOKEN_FIELD }}" value="{{ $registrationToken }}">
            {{-- Honeypot: off-screen, never filled by a person. --}}
            <div class="hp-field" aria-hidden="true">
                <label>Company website<input type="text" name="{{ \App\Services\RegistrationGuard::HONEYPOT_FIELD }}" tabindex="-1" autocomplete="off" value=""></label>
            </div>

            <label>First Name<input type="text" name="first_name" value="{{ old('first_name') }}" placeholder="Enter your first name" maxlength="60" autocomplete="given-name" required autofocus></label>
            <label>Last Name<input type="text" name="last_name" value="{{ old('last_name') }}" placeholder="Enter your last name" maxlength="60" autocomplete="family-name" required></label>
            <label>Phone Number<input type="tel" name="phone" value="{{ old('phone') }}" placeholder="09XXXXXXXX" inputmode="tel" maxlength="30" autocomplete="tel" required></label>
            @if ($emailMode !== \App\Services\RegistrationGuard::EMAIL_OFF)
                <label>Email{{ $emailMode === \App\Services\RegistrationGuard::EMAIL_REQUIRED ? '' : ' (Optional)' }}
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="you@example.com" maxlength="160" autocomplete="email" @required($emailMode === \App\Services\RegistrationGuard::EMAIL_REQUIRED)>
                    <small>{{ $emailMode === \App\Services\RegistrationGuard::EMAIL_REQUIRED ? 'We will send a confirmation link. Withdrawals open once it is confirmed.' : 'Used to confirm your account and recover access.' }}</small>
                </label>
            @endif
            <label>Username (Optional)<input type="text" name="username" value="{{ old('username') }}" placeholder="Chosen automatically if left blank" minlength="3" maxlength="32" pattern="[A-Za-z0-9_]+" autocomplete="username"></label>
            <label>Password<input type="password" name="password" placeholder="At least 8 characters" minlength="8" autocomplete="new-password" required></label>
            <label>Confirm Password<input type="password" name="password_confirmation" placeholder="Confirm your password" minlength="8" autocomplete="new-password" required></label>
            <label>Referral Code (Optional)<input type="text" name="referral_code" value="{{ old('referral_code') }}" placeholder="Enter referral code" maxlength="60"></label>

            @if ($turnstileSiteKey !== '')
                <div class="auth-turnstile">
                    <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="dark" data-action="register" data-size="flexible"></div>
                </div>
            @endif

            <button class="button button-primary button-wide auth-submit-btn" type="submit">Sign Up</button>
        </form>

        <p class="auth-switch">Already registered? <a href="{{ route('login') }}">Log in</a></p>
    </div>
</div>
@endsection
