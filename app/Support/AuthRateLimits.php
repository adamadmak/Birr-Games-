<?php

namespace App\Support;

use App\Models\SecurityEvent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Named rate limiters for the authentication endpoints.
 *
 * The plain `throttle:5,1` these replace answered a bare 429 page, which a
 * person who mistyped their password five times read as the site breaking.
 * These keep the same ceilings (and add per-account ones, so one address
 * cannot be hammered from many networks) but answer with the form and a
 * message, and write the refusal to the security log.
 */
final class AuthRateLimits
{
    public static function register(): void
    {
        RateLimiter::for('register', function (Request $request) {
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('register:m:'.$ip)->response(fn (Request $r, array $h) => self::refuse($r, SecurityEvent::TYPE_REGISTRATION, $h)),
                Limit::perHour(20)->by('register:h:'.$ip)->response(fn (Request $r, array $h) => self::refuse($r, SecurityEvent::TYPE_REGISTRATION, $h)),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            $ip = (string) $request->ip();
            $account = strtolower(trim((string) $request->input('phone', $request->input('email', $request->input('login', '')))));

            $limits = [
                Limit::perMinute(10)->by('login:ip:'.$ip)->response(fn (Request $r, array $h) => self::refuse($r, SecurityEvent::TYPE_LOGIN, $h)),
            ];

            if ($account !== '') {
                // Keyed by the account rather than the caller, so a credential-stuffing
                // run spread across proxies still stalls on the fifth guess.
                $limits[] = Limit::perMinute(5)->by('login:acct:'.sha1($account))->response(fn (Request $r, array $h) => self::refuse($r, SecurityEvent::TYPE_LOGIN, $h));
                $limits[] = Limit::perHour(30)->by('login:acct:h:'.sha1($account))->response(fn (Request $r, array $h) => self::refuse($r, SecurityEvent::TYPE_LOGIN, $h));
            }

            return $limits;
        });

        RateLimiter::for('recovery', function (Request $request) {
            $ip = (string) $request->ip();
            $account = strtolower(trim((string) $request->input('account', $request->input('email', ''))));

            $limits = [
                Limit::perMinute(5)->by('recovery:m:'.$ip)->response(fn (Request $r, array $h) => self::refuse($r, SecurityEvent::TYPE_PASSWORD_RESET, $h)),
                Limit::perHour(15)->by('recovery:h:'.$ip)->response(fn (Request $r, array $h) => self::refuse($r, SecurityEvent::TYPE_PASSWORD_RESET, $h)),
            ];

            if ($account !== '') {
                // Per account too, so one player's inbox cannot be flooded from many addresses.
                $limits[] = Limit::perHour(5)->by('recovery:acct:'.sha1($account))->response(fn (Request $r, array $h) => self::refuse($r, SecurityEvent::TYPE_PASSWORD_RESET, $h));
            }

            return $limits;
        });

        RateLimiter::for('verification', function (Request $request) {
            return Limit::perMinutes(10, 3)->by('verify:'.($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });
    }

    private static function refuse(Request $request, string $type, array $headers)
    {
        $retry = (int) ($headers['Retry-After'] ?? 60);
        $wait = $retry >= 120 ? ceil($retry / 60).' minutes' : max($retry, 1).' seconds';

        SecurityEvent::record(
            $type,
            SecurityEvent::OUTCOME_BLOCKED,
            'rate_limited',
            $request->ip(),
            $request->userAgent(),
            (string) $request->input('phone', $request->input('email', $request->input('username', $request->input('account', ''))))
        );

        $message = match ($type) {
            SecurityEvent::TYPE_LOGIN => "Too many sign-in attempts. Please wait {$wait} and try again.",
            SecurityEvent::TYPE_PASSWORD_RESET => "Too many password requests. Please wait {$wait} and try again.",
            default => "Too many sign-up attempts from your connection. Please wait {$wait} and try again.",
        };

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'error' => $message], 429, $headers);
        }

        return redirect()->back()
            ->withInput($request->except(['password', 'password_confirmation', '_token', 'cf-turnstile-response']))
            ->withErrors([match ($type) {
                SecurityEvent::TYPE_LOGIN => 'phone',
                SecurityEvent::TYPE_PASSWORD_RESET => 'account',
                default => 'registration',
            } => $message]);
    }
}
