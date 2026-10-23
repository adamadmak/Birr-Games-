@extends('layouts.app')

@section('title', 'Connect Telegram - Lucy Play')

@section('content')
<div class="player-account-page telegram-connect-page">
    <header class="player-page-heading">
        <p>{{ $welcome ? 'Your account is ready' : 'Player account' }}</p>
        <h1>Connect Telegram</h1>
        <span>Play, deposit and check your balance from the bot, and get your account updates in one place.</span>
    </header>

    @if (! $available)
        <section class="telegram-connect-card">
            <div class="telegram-connect-icon" aria-hidden="true">&#9993;</div>
            <div>
                <h2>Not available right now</h2>
                <p>The Telegram bot has not been set up yet, so there is nothing to connect. You can keep playing on the website.</p>
                <a class="button button-primary" href="{{ route('home') }}">Go to the lobby</a>
            </div>
        </section>
    @elseif ($subscriber)
        <section class="telegram-connect-card is-connected" data-telegram-connected>
            <div class="telegram-connect-icon" aria-hidden="true">&#10003;</div>
            <div>
                <h2>Telegram is connected</h2>
                <p>
                    @if ($subscriber->username)<strong>&#64;{{ $subscriber->username }}</strong> &middot; @endif
                    Linked to <strong>{{ \App\Support\PhoneNumber::format(auth()->user()->phone) }}</strong>.
                    Send <code>/start</code> to the bot any time to open your games.
                </p>
                <a class="button button-primary" href="{{ route('home') }}">Go to the lobby</a>
            </div>
        </section>

        <section class="telegram-connect-card telegram-connect-danger">
            <div>
                <h2>Disconnect</h2>
                <p>Your wallet and history stay as they are. The bot will no longer open this account.</p>
                <form method="POST" action="{{ route('player.telegram.unlink') }}" class="telegram-unlink-form">
                    @csrf
                    <label>Your password<input type="password" name="password" autocomplete="current-password" required></label>
                    <button class="button button-soft" type="submit">Disconnect Telegram</button>
                </form>
            </div>
        </section>
    @elseif ($limited || ! $link)
        <section class="telegram-connect-card">
            <div class="telegram-connect-icon" aria-hidden="true">&#9201;</div>
            <div>
                <h2>Please wait a little</h2>
                <p>You asked for several links in a short time. Try again in an hour, or open the bot and send <code>/start</code>.</p>
                <a class="button button-soft" href="{{ route('home') }}">Back to the lobby</a>
            </div>
        </section>
    @else
        <section class="telegram-connect-card" data-telegram-connect data-status-url="{{ route('player.telegram.status') }}" data-done-url="{{ route('home') }}">
            <div class="telegram-connect-icon" aria-hidden="true">&#9993;</div>
            <div>
                <h2>Open Telegram to connect</h2>
                <ol class="telegram-steps">
                    <li>Tap the button below. Telegram opens our bot.</li>
                    <li>Press <strong>Start</strong>, then <strong>Share My Phone Number</strong>.</li>
                    <li>That is all. This page updates by itself.</li>
                </ol>
                <div class="telegram-connect-actions">
                    <a class="button button-primary" href="{{ $link['url'] }}" target="_blank" rel="noopener" data-telegram-open>Open Telegram</a>
                    <a class="button button-ghost" href="{{ route('home') }}">Skip for now</a>
                </div>
                <p class="telegram-connect-note">The link works once and expires in {{ \App\Services\TelegramAccountLinker::CODE_MINUTES }} minutes. If the button does not open Telegram, open the bot and send <code>/link {{ $link['code'] }}</code>.</p>
                <p class="telegram-connect-waiting" data-telegram-waiting hidden>Waiting for Telegram&hellip;</p>
            </div>
        </section>
        <script src="/js/telegram-connect.js?v={{ @filemtime(public_path('js/telegram-connect.js')) ?: time() }}" defer></script>
    @endif
</div>
@endsection
