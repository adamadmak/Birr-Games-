<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A browser session outlives the account behind it. When an account is disabled — or
 * folded into another one by a duplicate merge — the old session would otherwise keep
 * showing that dead wallet, with its zero balance and no phone number.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->active) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $message = 'That account is closed. Open the Telegram bot and press 🎮 Play Games to continue on your active wallet.';

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error' => $message, 'reauthenticate' => true], 401);
            }

            return redirect()->route('home')->with('status', $message);
        }

        return $next($request);
    }
}
