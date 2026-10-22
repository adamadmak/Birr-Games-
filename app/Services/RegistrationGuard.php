<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Mailer;
use App\Support\Turnstile;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything that stands between a POST /register and a new account, other
 * than the field validation itself.
 *
 * Cheapest checks first, and every refusal is written to security_events so
 * the Security tab can show an attack while it is happening:
 *
 *   1. Honeypot — a field no person can see; a value in it is a script.
 *   2. Render token — issued when the form was shown, bound to the session,
 *      and carrying the time it was issued. Missing or forged means the POST
 *      did not come from our form; too fast means nobody typed into it.
 *   3. Turnstile — Cloudflare's human check, when an operator has set keys.
 *   4. Network quota — a cap on accounts per IP per day. Deliberately loose
 *      (Ethio Telecom puts whole towns behind one address) and configurable.
 *
 * Email verification policy also lives here because it decides what the
 * form asks for: off, optional, or required — and "required" quietly drops to
 * optional while no SMTP account is configured, so a missing mail server can
 * never lock every new player out of their wallet.
 */
final class RegistrationGuard
{
    public const HONEYPOT_FIELD = 'company_website';
    public const TOKEN_FIELD = '_rt';
    public const SESSION_NONCE = 'registration_nonce';

    public const SETTING_MIN_SECONDS = 'registration_min_seconds';
    public const SETTING_MAX_PER_IP_DAILY = 'registration_max_per_ip_daily';
    public const SETTING_EMAIL_MODE = 'registration_email_mode';

    public const EMAIL_OFF = 'off';
    public const EMAIL_OPTIONAL = 'optional';
    public const EMAIL_REQUIRED = 'required';

    /** A form older than this must be reloaded; it bounds how long a stolen token is useful. */
    private const TOKEN_LIFETIME_SECONDS = 2 * 60 * 60;

    /**
     * Throwaway mail providers. A short, well-known list on purpose: it stops
     * the lazy bulk sign-up without a dependency, and anything cleverer is
     * Turnstile's job.
     */
    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com',
        '10minutemail.net', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'yopmail.fr', 'trashmail.com',
        'getnada.com', 'dispostable.com', 'fakeinbox.com', 'maildrop.cc', 'mailnesia.com', 'throwawaymail.com',
        'tempr.email', 'discard.email', 'mohmal.com', 'emailondeck.com', 'mintemail.com', 'spamgourmet.com',
        'mytemp.email', 'tmpmail.org', 'tmpmail.net', 'burnermail.io', 'inboxkitten.com', 'moakt.com',
    ];

    public static function minSeconds(): int
    {
        $seconds = (int) PlatformSetting::valueFor(self::SETTING_MIN_SECONDS, 3);

        return max(0, min($seconds, 60));
    }

    public static function maxPerIpDaily(): int
    {
        $max = (int) PlatformSetting::valueFor(self::SETTING_MAX_PER_IP_DAILY, 10);

        return max(1, min($max, 1000));
    }

    /** The configured policy, before the mail-server check. */
    public static function configuredEmailMode(): string
    {
        $mode = (string) PlatformSetting::valueFor(self::SETTING_EMAIL_MODE, self::EMAIL_OPTIONAL);

        return in_array($mode, [self::EMAIL_OFF, self::EMAIL_OPTIONAL, self::EMAIL_REQUIRED], true) ? $mode : self::EMAIL_OPTIONAL;
    }

    /** The policy actually enforced: "required" needs a mail server to be honest. */
    public static function emailMode(): string
    {
        $mode = self::configuredEmailMode();

        return $mode === self::EMAIL_REQUIRED && ! Mailer::configured() ? self::EMAIL_OPTIONAL : $mode;
    }

    public static function collectsEmail(): bool
    {
        return self::emailMode() !== self::EMAIL_OFF;
    }

    public static function requiresEmail(): bool
    {
        return self::emailMode() === self::EMAIL_REQUIRED;
    }

    /**
     * A token for the form being rendered. Encrypted, so it cannot be forged
     * or back-dated, and bound to this session through a nonce so it cannot be
     * harvested from one browser and replayed from another.
     */
    public function issueToken(Request $request): string
    {
        $nonce = $request->session()->get(self::SESSION_NONCE);

        if (! is_string($nonce) || $nonce === '') {
            $nonce = Str::random(24);
            $request->session()->put(self::SESSION_NONCE, $nonce);
        }

        return Crypt::encrypt(['t' => time(), 'n' => $nonce]);
    }

    /**
     * Refuse anything that is not a person at our form.
     *
     * @throws ValidationException
     */
    public function inspect(Request $request): void
    {
        $ip = $request->ip();

        if (trim((string) $request->input(self::HONEYPOT_FIELD, '')) !== '') {
            $this->refuse($request, 'honeypot', 'We could not process your registration. Please try again.');
        }

        $token = $this->readToken($request);

        if ($token === null) {
            $this->refuse($request, 'bad_token', 'Your sign-up form has expired. Please reload the page and try again.');
        }

        $age = time() - $token['t'];

        if ($age > self::TOKEN_LIFETIME_SECONDS) {
            $this->refuse($request, 'stale_token', 'Your sign-up form has expired. Please reload the page and try again.');
        }

        if ($age < self::minSeconds()) {
            $this->refuse($request, 'too_fast', 'That was quick. Please take a moment to check your details and try again.');
        }

        $turnstile = Turnstile::verify($request->input('cf-turnstile-response'), $ip, 'register');

        if (! $turnstile['ok']) {
            $this->refuse($request, 'turnstile:'.$turnstile['reason'], $turnstile['reason'] === 'unreachable'
                ? 'The verification service is not reachable right now. Please try again in a minute.'
                : 'Please complete the "I am human" check and try again.');
        }

        if ($ip !== null && $this->accountsFromIpToday($ip) >= self::maxPerIpDaily()) {
            $this->refuse($request, 'ip_quota', 'Too many accounts have been created from this network today. Please contact support if you need help.');
        }
    }

    /** Called once the account exists: the row the network quota counts. */
    public function recordSuccess(Request $request, User $user): void
    {
        SecurityEvent::record(
            SecurityEvent::TYPE_REGISTRATION,
            SecurityEvent::OUTCOME_ALLOWED,
            null,
            $request->ip(),
            $request->userAgent(),
            $user->phone ?: $user->username,
            $user->id,
            ['email' => $user->email !== null]
        );

        $request->session()->forget(self::SESSION_NONCE);
    }

    public static function isDisposableEmail(string $email): bool
    {
        $domain = strtolower(trim((string) Str::after($email, '@')));

        return $domain !== '' && in_array($domain, self::DISPOSABLE_DOMAINS, true);
    }

    /** @return array{t: int, n: string}|null */
    private function readToken(Request $request): ?array
    {
        $raw = $request->input(self::TOKEN_FIELD);

        if (! is_string($raw) || $raw === '' || strlen($raw) > 1024) {
            return null;
        }

        try {
            $payload = Crypt::decrypt($raw);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['t'], $payload['n']) || ! is_int($payload['t']) || ! is_string($payload['n'])) {
            return null;
        }

        $nonce = $request->session()->get(self::SESSION_NONCE);

        if (! is_string($nonce) || ! hash_equals($nonce, $payload['n'])) {
            return null;
        }

        return ['t' => $payload['t'], 'n' => $payload['n']];
    }

    private function accountsFromIpToday(string $ip): int
    {
        $since = now()->subDay();

        try {
            $fromLog = SecurityEvent::query()
                ->where('type', SecurityEvent::TYPE_REGISTRATION)
                ->where('outcome', SecurityEvent::OUTCOME_ALLOWED)
                ->where('ip', $ip)
                ->where('created_at', '>=', $since)
                ->count();
        } catch (\Throwable) {
            $fromLog = 0;
        }

        try {
            $fromUsers = User::withTrashed()
                ->where('registration_ip', $ip)
                ->where('created_at', '>=', $since)
                ->count();
        } catch (\Throwable) {
            $fromUsers = 0;
        }

        return max($fromLog, $fromUsers);
    }

    /** @throws ValidationException */
    private function refuse(Request $request, string $reason, string $message): never
    {
        SecurityEvent::record(
            SecurityEvent::TYPE_REGISTRATION,
            SecurityEvent::OUTCOME_BLOCKED,
            $reason,
            $request->ip(),
            $request->userAgent(),
            (string) $request->input('phone', $request->input('username', ''))
        );

        Log::warning('Registration refused.', ['reason' => $reason, 'ip' => $request->ip()]);

        throw ValidationException::withMessages(['registration' => $message]);
    }
}
