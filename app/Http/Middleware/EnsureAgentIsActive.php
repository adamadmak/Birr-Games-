<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An agent's session and remember-me cookie outlive the account behind them.
 *
 * Until 28 Oct 2026 the active flag was checked at login only, so an agent
 * disabled by an administrator kept approving deposits, paying withdrawals
 * and moving credit for as long as their browser stayed signed in. Every
 * request on the agent portal now re-reads the flag from the database (not
 * from the user object the guard cached at login) and ends the session the
 * moment it is off. EnsureAccountIsActive does the same for players on the
 * web guard; this one is for the agent guard.
 */
class EnsureAgentIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $agent = $request->user('agent');

        if ($agent instanceof Agent) {
            $fresh = Agent::query()->find($agent->getKey());

            if ($fresh === null || ! $fresh->active) {
                Auth::guard('agent')->logout();

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                if ($request->expectsJson()) {
                    return response()->json(['ok' => false, 'error' => 'This agent account is disabled.'], 403);
                }

                return redirect()->route('agent.login')->withErrors(['phone' => 'This agent account has been disabled. Contact the administrator.']);
            }
        }

        return $next($request);
    }
}
