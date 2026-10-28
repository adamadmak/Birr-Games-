@extends('layouts.app')
@section('title', __('Responsible play') . ' — Vamoseone')
@section('content')
<div class="content-page prose-page">
    <p class="eyebrow">{{ __('Player care') }}</p>
    <h1>{{ __('Keep play in perspective.') }}</h1>
    <p>{{ __('Betting is entertainment, paid for with money you can afford to lose. Decide what you will spend before you start, treat a win as a bonus rather than an income, and stop whenever it stops being fun.') }}</p>
    <div class="care-grid">
        <article><strong>{{ __('Set a budget') }}</strong><p>{{ __('Decide how much you will deposit this week and keep to it, win or lose.') }}</p></article>
        <article><strong>{{ __('Set a time') }}</strong><p>{{ __('Decide how long you want to play before starting, and take a break after a run of bets.') }}</p></article>
        <article><strong>{{ __('Never chase') }}</strong><p>{{ __('A loss is not a reason to bet more. Chasing it is how small losses become large ones.') }}</p></article>
        <article><strong>{{ __('Keep it social') }}</strong><p>{{ __('Play should never come before family, work or sleep. If it does, pause.') }}</p></article>
    </div>
    <h2>{{ __('Tools you can ask for') }}</h2>
    <p>{{ __('Message support and we will set a deposit limit on your account, pause it for a period you choose, or close it. A pause or closure takes effect straight away and is not undone on request during the period you chose.') }} <a href="{{ \App\Models\PlatformSetting::telegramSupportUrl() }}" target="_blank" rel="noopener">{{ __('Chat with support') }}</a></p>
    <h2>{{ __('Signs to watch for') }}</h2>
    <ul>
        <li>{{ __('Betting more than you planned, or borrowing to bet.') }}</li>
        <li>{{ __('Hiding how much you play from people close to you.') }}</li>
        <li>{{ __('Feeling restless or irritable when you are not playing.') }}</li>
        <li>{{ __('Betting to escape problems or to win back losses.') }}</li>
    </ul>
    <p>{{ __('If any of these sound familiar, talk to someone you trust and ask us to pause your account.') }}</p>
    <p class="prose-note">{{ __('Vamoseone is for adults only. You must be 18 or older to open an account.') }}</p>
</div>
@endsection
