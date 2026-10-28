@extends('layouts.app')
@section('title', __('About Vamoseone') . ' — Vamoseone')
@section('content')
<div class="content-page prose-page">
    <p class="eyebrow">{{ __('About Vamoseone') }}</p>
    <h1>{{ __('Sports betting and casino games, built for Ethiopia.') }}</h1>
    <p>{{ __('Vamoseone is an online sportsbook and casino for players in Ethiopia. Bet on football, basketball and more before kick-off or live, and play slots, crash games and table games between matches. Everything runs in Ethiopian Birr.') }}</p>
    <h2>{{ __('Your money') }}</h2>
    <p>{{ __('Deposits are made to our payment agents through the bank and mobile money accounts shown in your wallet, and are credited once the agent confirms the transfer. Withdrawals are requested from the same wallet, reviewed by our team and paid to the account you name.') }}</p>
    <h2>{{ __('Fair and accountable') }}</h2>
    <p>{{ __('Odds and casino results come from the licensed providers that run the games; every bet, win, deposit and withdrawal is recorded in your transaction history so you can check it at any time.') }}</p>
    <h2>{{ __('Help') }}</h2>
    <p>{{ __('Our support team is on Telegram every day. If something about a bet, a deposit or your account does not look right, message us before you play on.') }} <a href="{{ \App\Models\PlatformSetting::telegramSupportUrl() }}" target="_blank" rel="noopener">{{ __('Chat with support') }}</a></p>
    <p class="prose-note">{{ __('Vamoseone is for adults only. You must be 18 or older to open an account.') }}</p>
</div>
@endsection
