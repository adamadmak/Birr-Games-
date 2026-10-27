@extends('layouts.app')

@section('title', 'Create an account — Vamoseone')

@if ($turnstileSiteKey !== '')
    @push('head')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif

@section('content')
@php
    /* A phone number is the account. It goes first, the name follows, and the
       extras (email, username, referral code) fold away unless an operator has
       made email required, in which case it stays in view. */
    $emailRequired = $emailMode === \App\Services\RegistrationGuard::EMAIL_REQUIRED;
    $emailShown = $emailMode !== \App\Services\RegistrationGuard::EMAIL_OFF;
    $extrasOpen = old('username') || old('referral_code') || (! $emailRequired && old('email'));
@endphp
<div class="auth-modal-wrapper">
    <div class="auth-card auth-modal-card">
        <header class="auth-card-header">
            <div>
                <h2>{{ __('Create your account') }}</h2>
                <p class="auth-card-lead">{{ __('Takes a minute. Your phone number is your login.') }}</p>
            </div>
            <a class="auth-close-btn" href="{{ url('/') }}" aria-label="Close">&times;</a>
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

            <label>{{ __('Phone number') }}<input type="tel" name="phone" value="{{ old('phone') }}" placeholder="09XXXXXXXX" inputmode="tel" maxlength="30" autocomplete="tel" required autofocus></label>

            <div class="auth-name-row">
                <label>{{ __('First name') }}<input type="text" name="first_name" value="{{ old('first_name') }}" placeholder="{{ __('First name') }}" maxlength="60" autocomplete="given-name" required></label>
                <label>{{ __('Last name') }}<input type="text" name="last_name" value="{{ old('last_name') }}" placeholder="{{ __('Last name') }}" maxlength="60" autocomplete="family-name" required></label>
            </div>

            @if ($emailShown && $emailRequired)
                <label>{{ __('Email') }}
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="you@example.com" maxlength="160" autocomplete="email" required>
                    <small>{{ __('We will send a confirmation link. Withdrawals open once it is confirmed.') }}</small>
                </label>
            @endif

            <label>{{ __('Password') }}<input type="password" name="password" placeholder="{{ __('At least 8 characters') }}" minlength="8" autocomplete="new-password" required></label>
            <label>{{ __('Confirm password') }}<input type="password" name="password_confirmation" placeholder="{{ __('Type it again') }}" minlength="8" autocomplete="new-password" required></label>

            <details class="auth-more" @if ($extrasOpen) open @endif>
                <summary>{{ __('More options') }} <small>{{ __('email, username, referral code') }}</small></summary>
                @if ($emailShown && ! $emailRequired)
                    <label>{{ __('Email') }} <em>{{ __('optional') }}</em>
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="you@example.com" maxlength="160" autocomplete="email">
                        <small>{{ __('Used to confirm your account and recover access.') }}</small>
                    </label>
                @endif
                <label>{{ __('Username') }} <em>{{ __('optional') }}</em><input type="text" name="username" value="{{ old('username') }}" placeholder="{{ __('Chosen automatically if left blank') }}" minlength="3" maxlength="32" pattern="[A-Za-z0-9_]+" autocomplete="username"></label>
                <label>{{ __('Referral code') }} <em>{{ __('optional') }}</em><input type="text" name="referral_code" value="{{ old('referral_code') }}" placeholder="{{ __('Enter referral code') }}" maxlength="60"></label>
            </details>

            @if ($turnstileSiteKey !== '')
                <div class="auth-turnstile">
                    <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="dark" data-action="register" data-size="flexible"></div>
                </div>
            @endif

            <button class="button button-primary button-wide auth-submit-btn" type="submit">{{ __('Create account') }}</button>
            <p class="auth-fineprint">{{ __('18+ only. By signing up you agree to play responsibly.') }}</p>
        </form>

        <p class="auth-switch">{{ __('Already registered?') }} <a href="{{ route('login') }}">{{ __('Log in') }}</a></p>
    </div>
</div>
@endsection
