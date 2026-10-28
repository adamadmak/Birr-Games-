<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Where the QTech (QT) Common Wallet configuration comes from.
 *
 * Same resolver shape as App\Support\Winhouse, and for the same reason: this
 * host builds its config cache without the runtime environment, so
 * config('services.qtech.*') is empty in production even though the dashboard
 * has the value. The order is config → platform_settings → process env.
 *
 * Unlike Winhouse there is deliberately NO bound literal fallback here. The
 * pass-key is the only thing standing between the public internet and this
 * wallet's debit endpoint, so an unconfigured deployment must authenticate
 * nobody rather than fall back to a secret committed in the repository.
 */
final class Qtech
{
    /** QT's own timeouts are 3s connect / 5s read — answer well inside that. */
    public const READ_TIMEOUT_SECONDS = 5;

    /** The two environments QTech configure separately on their side. */
    public const PRODUCTION = 'production';
    public const STAGING = 'staging';

    /**
     * The shared secret for one environment.
     *
     * QTech register a Base URL and a pass key per environment and will not
     * accept the same URL in both slots, so this deployment answers staging on
     * its own route prefix. Both prefixes reach the same application and the
     * same database — there is no separate staging system and QTech have been
     * told so — but the keys are genuinely distinct, so a staging key that
     * leaks cannot authorise anything on the production path.
     */
    public static function passKey(string $environment = self::PRODUCTION): string
    {
        return self::value($environment === self::STAGING ? 'pass_key_staging' : 'pass_key');
    }

    public static function currency(): string
    {
        return strtoupper(self::value('currency') ?: 'ETB');
    }

    public static function configured(string $environment = self::PRODUCTION): bool
    {
        return self::passKey($environment) !== '';
    }

    /**
     * How long a wallet session stays good for a bet.
     *
     * Deposits and rollbacks ignore this entirely; it only bounds how long a
     * launch token can keep opening new bets.
     */
    /**
     * QTech's own API — the half of the integration where WE call THEM: token
     * retrieval, the game list, and the per-launch URL. Integration host until
     * QTech hand over production; then it is one setting change.
     */
    public static function apiBaseUrl(): string
    {
        $url = self::value('api_base_url');

        return rtrim($url !== '' ? $url : 'https://api-int.qtplatform.com', '/');
    }

    /**
     * Whether we are pointed at QTech's integration (test) environment.
     *
     * The decisive fact about api-int: the games it serves are test games on
     * QTech's side, but a bet placed in one is a REAL debit from our wallet.
     * So while this is true, QTech games must not reach real players — the
     * lobby hides them and the launcher refuses them — and only the fixture
     * player minted for verification may open one. On the production host
     * this is false and nothing is gated.
     */
    public static function integrationMode(): bool
    {
        $host = strtolower((string) parse_url(self::apiBaseUrl(), PHP_URL_HOST));

        return $host === '' || str_contains($host, '-int.') || str_starts_with($host, 'int.');
    }

    /**
     * The operator's deliberate go-live decision for QTech games.
     *
     * QTech require their own production Common Wallet test before go-live,
     * and that happens AFTER they issue production API credentials — so there
     * is a window where the API base URL points at their production host (to
     * sync the production catalogue and play-test) while real players must
     * still not see the games. Pointing at production must therefore never
     * publish anything by itself; this switch does, and only when an operator
     * turns it on. Off by default.
     */
    public static function liveToPlayers(): bool
    {
        try {
            return (bool) \App\Models\PlatformSetting::valueFor('qtech_live_to_players', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether QTech games are held back from real players right now: always
     * on the integration host, and on the production host until the operator
     * turns them live. The fixture player is the only exception, by explicit
     * bypass on the game page and the launcher.
     */
    public static function heldFromPlayers(): bool
    {
        return self::integrationMode() || ! self::liveToPlayers();
    }

    /** The account allowed to play-test QTech games while on the integration host. */
    public static function isFixturePlayer(?\App\Models\User $user): bool
    {
        return $user !== null && $user->username === \App\Services\QtechTestCredentials::PLAYER;
    }

    public static function apiUsername(): string
    {
        return self::value('api_username');
    }

    public static function apiPassword(): string
    {
        return self::value('api_password');
    }

    /** Whether we hold what is needed to call QTech at all. */
    public static function apiConfigured(): bool
    {
        return self::apiUsername() !== '' && self::apiPassword() !== '';
    }

    /**
     * Bearer for the external timer that drives the catalog refresh. Falls back
     * to the BigBang timer's token: it is the operator's own timer hitting two
     * sync endpoints on the same host, and one credential for it is the point.
     */
    public static function syncToken(): string
    {
        $token = self::value('sync_token');

        return $token !== '' ? $token : BigBang::syncToken();
    }

    /** ISO 3166-1 alpha-2 of the players — QTech require it on every launch. */
    public static function country(): string
    {
        $country = self::value('country');

        return $country !== '' ? strtoupper($country) : 'ET';
    }

    /** Game language as QTech spell it: ISO 639-1 + ISO 3166-1, e.g. en_US. */
    public static function lang(): string
    {
        $lang = self::value('lang');

        return $lang !== '' ? $lang : 'en_US';
    }

    /**
     * Hosts a launch URL may point at. Matched as the host itself or any
     * subdomain, because QTech serve games from a family of launcher hosts
     * (gl-int.qtplatform.com on integration) rather than one fixed name.
     *
     * @return list<string>
     */
    public static function allowedLaunchHosts(): array
    {
        $raw = self::value('allowed_launch_hosts');
        $hosts = explode(',', $raw !== '' ? $raw : 'qtplatform.com,qtlauncher.com');

        return array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            $hosts,
        )));
    }

    public static function sessionLifetimeHours(): int
    {
        $hours = (int) (self::value('session_hours') ?: 0);

        return $hours > 0 ? min($hours, 24) : 6;
    }

    private static function value(string $name): string
    {
        $value = trim((string) config('services.qtech.'.$name));

        if ($value === '') {
            // Wrapped: a settings lookup that throws (no table yet on a fresh
            // deploy, or a database blip) must read as "not configured" rather
            // than take down a wallet callback.
            try {
                $value = trim((string) PlatformSetting::valueFor('qtech_'.$name, ''));
            } catch (\Throwable) {
                $value = '';
            }
        }

        if ($value === '') {
            $value = trim((string) (getenv('QTECH_'.strtoupper($name)) ?: ''));
        }

        if ($value === '') {
            $value = self::fromEnvFile('QTECH_'.strtoupper($name));
        }

        return $value;
    }

    /**
     * Read the key straight out of the .env file on disk.
     *
     * The level that actually works on this host, and the reason there is no
     * committed literal here. The platform runs `config:cache` at BUILD time,
     * without the dashboard variables — and when the config is cached Laravel
     * skips LoadEnvironmentVariables entirely, so .env is never parsed and
     * config(), env() and $_ENV are all empty at runtime. getenv() does not see
     * them either on this host; BigBangWalletController and App\Support\Winhouse
     * both document the same dead end and solve it with a literal in the
     * repository, which is not a trade worth making for a key that authorises
     * debits.
     *
     * The container entrypoint does write a real .env from the environment on
     * every boot ("[entrypoint] .env ready"), so the value is on disk — it is
     * only Laravel's own loader that skips it. Reading the file directly needs
     * no secret in git and no deploy to rotate: change it in the dashboard,
     * redeploy, done.
     *
     * Parsed once per process and memoised; a miss returns '' and the caller
     * fails closed exactly as before.
     */
    private static function fromEnvFile(string $key): string
    {
        static $parsed = null;

        if ($parsed === null) {
            $parsed = [];
            $path = base_path('.env');

            if (is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);

                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }

                    $split = strpos($line, '=');

                    if ($split === false) {
                        continue;
                    }

                    $name = trim(substr($line, 0, $split));
                    $value = trim(substr($line, $split + 1));

                    // The entrypoint quotes values that contain spaces or ':'.
                    $quoted = strlen($value) >= 2
                        && ($value[0] === '"' || $value[0] === "'")
                        && $value[strlen($value) - 1] === $value[0];

                    $parsed[$name] = $quoted ? substr($value, 1, -1) : $value;
                }
            }
        }

        return trim((string) ($parsed[$key] ?? ''));
    }
}
