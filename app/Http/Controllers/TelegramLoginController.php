<?php

namespace App\Http\Controllers;

use App\Models\TelegramLoginToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TelegramLoginController
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        // 1. Resolve the token first. A browser session can belong to an account that has
        //    since been disabled or merged away, so the token always wins over the session.
        //    A link lives for the 24 hours it was issued with, and revoking it (a merge, an
        //    unlink, an admin closing the account) sets expires_at to now, so the two are
        //    one check. Until 28 Oct 2026 neither was checked and every link ever sent was
        //    a permanent sign-in.
        $record = TelegramLoginToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        // A link may be opened again within a few minutes of its first use — Telegram's
        // in-app browser hands a link to the system browser by opening it twice — but
        // not later: after that it is spent.
        if ($record && $record->used_at && $record->used_at->lt(now()->subMinutes(10))) {
            $record = null;
        }

        // 2. If the token is valid, sign the player in — switching accounts when the session
        //    is holding a different (usually stale) player.
        if ($record && $record->user && $record->user->active) {
            if (! Auth::check() || Auth::id() !== $record->user->id) {
                Auth::login($record->user);
                $request->session()->regenerate();
            }

            if (! $record->used_at) {
                $record->update(['used_at' => now()]);
            }

            return $record->destination === 'deposit'
                ? redirect('/wallet?tab=deposit')
                : redirect('/');
        }

        // 3. No usable token: an existing healthy session may simply continue.
        if (Auth::check() && $request->user()->active) {
            return redirect('/');
        }

        // 4. Graceful fallback: Redirect to home instead of throwing a 410 error page
        return redirect('/')->with('status', 'Welcome! Return to Telegram bot and press 🎮 Play Games any time.');
    }
}
