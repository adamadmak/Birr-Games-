@extends('layouts.app')

@section('title', __('Choose a new password') . ' — Vamoseone')

@section('content')
<div class="auth-modal-wrapper">
    <div class="auth-card auth-modal-card">
        <header class="auth-card-header">
            <h2>{{ __('Choose a new password') }}</h2>
            <a class="auth-close-btn" href="{{ route('login') }}" aria-label="{{ __('Close') }}">&times;</a>
        </header>

        @if ($errors->any())
            <div class="form-errors">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="stacked-form">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label>{{ __('Email') }}<input type="email" name="email" value="{{ old('email', $email) }}" maxlength="160" autocomplete="email" required></label>
            <label>{{ __('New password') }}<input type="password" name="password" autocomplete="new-password" minlength="8" required autofocus><small>{{ __('At least 8 characters.') }}</small></label>
            <label>{{ __('Repeat the new password') }}<input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required></label>
            <button class="button button-primary button-wide auth-submit-btn" type="submit">{{ __('Save password') }}</button>
        </form>

        <p class="auth-switch">{{ __('Every other browser signed in to this account will be signed out.') }}</p>
    </div>
</div>
@endsection
