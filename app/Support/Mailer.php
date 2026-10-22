<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Outgoing mail, configured from the admin rather than the environment.
 *
 * config/mail.php reads MAIL_* from env, and on this host env is empty at
 * runtime (App\Support\RuntimeEnv explains why), so the SMTP account lives in
 * platform_settings and is applied to the mail config just before a message
 * is sent. Nothing is sent, and no feature that depends on mail is enforced,
 * until an operator has saved a host.
 */
final class Mailer
{
    public const SETTINGS = [
        'mail_host', 'mail_port', 'mail_username', 'mail_password',
        'mail_encryption', 'mail_from_address', 'mail_from_name',
    ];

    public static function host(): string
    {
        return RuntimeEnv::get(null, 'mail_host', 'MAIL_HOST');
    }

    public static function fromAddress(): string
    {
        return RuntimeEnv::get(null, 'mail_from_address', 'MAIL_FROM_ADDRESS');
    }

    /** Whether we have enough to attempt delivery at all. */
    public static function configured(): bool
    {
        return self::host() !== '' && filter_var(self::fromAddress(), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Push the stored SMTP account into the live mail config. Idempotent and
     * cheap, so callers run it right before Mail::send().
     */
    public static function prepare(): void
    {
        if (! self::configured()) {
            return;
        }

        $port = (int) RuntimeEnv::get(null, 'mail_port', 'MAIL_PORT', '587');
        $encryption = strtolower(RuntimeEnv::get(null, 'mail_encryption', 'MAIL_ENCRYPTION', 'tls'));
        $username = RuntimeEnv::get(null, 'mail_username', 'MAIL_USERNAME');
        $password = RuntimeEnv::get(null, 'mail_password', 'MAIL_PASSWORD');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => self::host(),
            'mail.mailers.smtp.port' => $port > 0 ? $port : 587,
            'mail.mailers.smtp.encryption' => in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null,
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : null,
            'mail.mailers.smtp.username' => $username !== '' ? $username : null,
            'mail.mailers.smtp.password' => $password !== '' ? $password : null,
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => self::fromAddress(),
            'mail.from.name' => RuntimeEnv::get(null, 'mail_from_name', 'MAIL_FROM_NAME', (string) PlatformSetting::valueFor('site_title', config('app.name', 'Lucy Play'))),
        ]);

        // The manager memoises a mailer once built; drop it so these values apply.
        try {
            app('mail.manager')->purge('smtp');
        } catch (\Throwable) {
            // Not built yet — nothing to purge.
        }
    }
}
