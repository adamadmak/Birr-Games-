<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * One resolver for every operator-set value on this host.
 *
 * The platform builds Laravel's config cache at BUILD time, without the
 * runtime environment, and a cached config makes Laravel skip .env entirely —
 * so config(), env() and $_ENV are empty for anything set in the dashboard.
 * App\Support\Qtech documents the full story; this is the same ladder made
 * reusable so that new integrations do not each copy it:
 *
 *   config key → platform_settings → process env → .env file on disk
 *
 * The first non-empty value wins. Secrets that authorise anything should be
 * read through here and never carry a committed literal default.
 */
final class RuntimeEnv
{
    /** @var array<string, string>|null */
    private static ?array $envFile = null;

    /**
     * @param  string|null  $configKey  dot-path into config(), or null to skip it
     * @param  string|null  $settingKey  platform_settings key, or null to skip it
     * @param  string|null  $envKey  process / .env variable name, or null to skip it
     */
    public static function get(?string $configKey, ?string $settingKey, ?string $envKey, string $default = ''): string
    {
        $value = $configKey !== null ? trim((string) config($configKey)) : '';

        if ($value === '' && $settingKey !== null) {
            try {
                $value = trim((string) PlatformSetting::valueFor($settingKey, ''));
            } catch (\Throwable) {
                $value = '';
            }
        }

        if ($value === '' && $envKey !== null) {
            $value = trim((string) (getenv($envKey) ?: ''));
        }

        if ($value === '' && $envKey !== null) {
            $value = self::fromEnvFile($envKey);
        }

        return $value !== '' ? $value : $default;
    }

    /**
     * Read a variable straight out of the .env file on disk.
     *
     * The container entrypoint writes a real .env from the dashboard variables
     * on every boot; it is only Laravel's own loader that skips it once the
     * config is cached. Parsed once per process and memoised.
     */
    public static function fromEnvFile(string $key): string
    {
        if (self::$envFile === null) {
            self::$envFile = [];
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

                    $quoted = strlen($value) >= 2
                        && ($value[0] === '"' || $value[0] === "'")
                        && $value[strlen($value) - 1] === $value[0];

                    self::$envFile[$name] = $quoted ? substr($value, 1, -1) : $value;
                }
            }
        }

        return trim((string) (self::$envFile[$key] ?? ''));
    }

    /** Test seam: forget the parsed .env so the next read re-parses. */
    public static function flush(): void
    {
        self::$envFile = null;
    }
}
