@extends('layouts.app')

@section('title', __('Forgot password') . ' — Vamoseone')

@section('content')
<div class="auth-modal-wrapper">
    <div class="auth-card auth-modal-card">
        <header class="auth-card-header">
            <h2>{{ __('Forgot your password?') }}</h2>
            <a class="auth-close-btn" href="{{ route('login') }}" aria-label="{{ __('Close') }}">&times;</a>
        </header>

        @if ($errors->any())
            <div class="form-errors">{{ $errors->first() }}</div>
        @endif

        @if (session('recovery_sent'))
            <div class="form-success">{{ __('If that account has a confirmed email address, a reset link is on its way. Check your inbox and spam folder. Otherwise use Telegram below.') }}</div>
        @endif

        <section class="recovery-option">
            <h3>{{ __('Fastest: get a new password in Telegram') }}</h3>
            <p>{{ __('Open our bot, share your contact when it asks, and send /password. It replies with a new password for the phone number on your account.') }}</p>
            @if ($botUrl !== '')
                <a class="button button-primary button-wide" href="{{ $botUrl }}" target="_blank" rel="noopener">{{ __('Open Telegram') }}</a>
            @else
                <a class="button button-primary button-wide" href="{{ $supportUrl }}" target="_blank" rel="noopener">{{ __('Chat with support') }}</a>
            @endif
        </section>

        @if ($mailReady)
            <section class="recovery-option divided">
                <h3>{{ __('By email') }}</h3>
                <p>{{ __('Works when your account has a confirmed email address.') }}</p>
                <form method="POST" action="{{ route('password.email') }}" class="stacked-form">
                    @csrf
                    <label>{{ __('Phone number, username or email') }}<input type="text" name="account" value="{{ old('account') }}" maxlength="190" autocomplete="username" required></label>
                    <button class="button button-soft button-wide" type="submit">{{ __('Send reset link') }}</button>
                </form>
            </section>
        @endif

        <p class="auth-switch">{{ __('Still stuck?') }} <a href="{{ $supportUrl }}" target="_blank" rel="noopener">{{ __('Chat with support') }}</a></p>
        <p class="auth-switch"><a href="{{ route('login') }}">{{ __('Back to log in') }}</a></p>
    </div>
</div>
@endsection
