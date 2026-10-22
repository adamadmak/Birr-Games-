<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile: the human check on the registration (and, optionally,
 * the login) form.
 *
 * Keys come from platform_settings first — the admin's Security tab — because
 * dashboard environment variables never reach this app at runtime (see
 * App\Support\RuntimeEnv). The site key is public by design and is rendered
 * into the form; the secret is only ever sent from here to Cloudflare.
 *
 * Unconfigured means OFF, not broken: the form renders without a widget and
 * the other guards (honeypot, timing, rate limits) still apply. Once a secret
 * is set a missing or invalid token refuses the submission.
 */
final class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const SETTING_SITE_KEY = 'turnstile_site_key';
    public const SETTING_SECRET = 'turnstile_secret_key';
    public const SETTING_PROTECT_LOGIN = 'turnstile_protect_login';

    public static function siteKey(): string
    {
        return RuntimeEnv::get('services.turnstile.site_key', self::SETTING_SITE_KEY, 'TURNSTILE_SITE_KEY');
    }

    public static function secret(): string
    {
        return RuntimeEnv::get('services.turnstile.secret_key', self::SETTING_SECRET, 'TURNSTILE_SECRET_KEY');
    }

    public static function configured(): bool
    {
        return self::siteKey() !== '' && self::secret() !== '';
    }

    /** Whether the login form carries the widget too. Off by default: registration is where the abuse is. */
    public static function protectsLogin(): bool
    {
        if (! self::configured()) {
            return false;
        }

        try {
            return (bool) \App\Models\PlatformSetting::valueFor(self::SETTING_PROTECT_LOGIN, false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check a token with Cloudflare.
     *
     * @return array{ok: bool, reason: string}
     */
    public static function verify(?string $token, ?string $ip = null, ?string $expectedAction = null): array
    {
        if (! self::configured()) {
            return ['ok' => true, 'reason' => 'not_configured'];
        }

        $token = trim((string) $token);

        if ($token === '' || strlen($token) > 2048) {
            return ['ok' => false, 'reason' => 'missing_token'];
        }

        try {
            $response = Http::asForm()
                ->timeout(6)
                ->connectTimeout(3)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => self::secret(),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (\Throwable $exception) {
            // Cloudflare unreachable from this host. Fail closed: a registration
            // form that silently drops its human check during an outage is the
            // moment a bot farm would choose.
            Log::warning('Turnstile verification could not reach Cloudflare.', ['error' => $exception->getMessage()]);

            return ['ok' => false, 'reason' => 'unreachable'];
        }

        $body = $response->json();

        if (! is_array($body)) {
            return ['ok' => false, 'reason' => 'bad_response'];
        }

        if (($body['success'] ?? false) !== true) {
            $codes = $body['error-codes'] ?? [];

            return ['ok' => false, 'reason' => is_array($codes) && $codes !== [] ? implode(',', array_map('strval', $codes)) : 'rejected'];
        }

        if ($expectedAction !== null && isset($body['action']) && $body['action'] !== $expectedAction) {
            return ['ok' => false, 'reason' => 'action_mismatch'];
        }

        return ['ok' => true, 'reason' => 'verified'];
    }
}
