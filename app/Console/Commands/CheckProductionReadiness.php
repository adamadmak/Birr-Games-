<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CheckProductionReadiness extends Command
{
    protected $signature = 'production:check';
    protected $description = 'Fail when critical production security or runtime configuration is unsafe';

    public function handle(): int
    {
        $payoutDisk = (string) config('filesystems.payout_proof_disk');
        $payoutDiskConfig = config("filesystems.disks.{$payoutDisk}");
        $payoutDriver = is_array($payoutDiskConfig) ? ($payoutDiskConfig['driver'] ?? null) : null;
        $durablePayoutStorage = is_array($payoutDiskConfig)
            && !in_array($payoutDriver, ['local'], true);
        $durablePayoutStorageConfigured = $durablePayoutStorage
            && ($payoutDriver !== 's3'
                || (
                    filled($payoutDiskConfig['bucket'] ?? null)
                    && filled($payoutDiskConfig['key'] ?? null)
                    && filled($payoutDiskConfig['secret'] ?? null)
                ));

        $checks = [
            ['APP_ENV is production', app()->environment('production')],
            ['APP_DEBUG is disabled', config('app.debug') === false],
            ['APP_KEY is configured', Str::startsWith((string) config('app.key'), ['base64:', 'hex:']) || strlen((string) config('app.key')) >= 32],
            ['APP_URL uses HTTPS', Str::startsWith((string) config('app.url'), 'https://')],
            ['Database is not SQLite', config('database.default') !== 'sqlite'],
            ['Sessions are not stored in files', config('session.driver') !== 'file'],
            ['Session payloads are encrypted', config('session.encrypt') === true],
            ['Session cookies require HTTPS', config('session.secure') === true],
            ['Session cookies are HttpOnly', config('session.http_only') === true],
            ['Queues are asynchronous', config('queue.default') !== 'sync'],
            ['Payout evidence uses durable storage', $durablePayoutStorage],
            ['Payout evidence storage is configured', $durablePayoutStorageConfigured],
            ['Timezone is Africa/Addis_Ababa', config('app.timezone') === 'Africa/Addis_Ababa'],
            ['Trusted proxy source is explicit', filled(env('TRUSTED_PROXIES')) && env('TRUSTED_PROXIES') !== '*'],
            ['BigBang API uses the /api/v1 contract', Str::endsWith(rtrim((string) config('services.bigbang.base_url'), '/'), '/api/v1')],
            ['BigBang is in live mode', config('services.bigbang.mode') === 'live'],
            ['BigBang live API key is configured', \App\Support\BigBang::apiKey() !== '' && !Str::startsWith(\App\Support\BigBang::apiKey(), 'ek_test_')],
            ['BigBang account currency is ETB', strtoupper((string) config('services.bigbang.currency')) === 'ETB'],
            ['BigBang callback token is configured', strlen(\App\Support\BigBang::callbackToken()) >= 32],
            ['BigBang webhook secret is configured', strlen((string) config('services.bigbang.webhook_secret')) >= 32],
            ['Telegram webhook secret is configured', strlen((string) config('services.telegram.webhook_secret')) >= 32],
        ];

        $failed = 0;
        foreach ($checks as [$label, $passed]) {
            $this->line(($passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>').'  '.$label);
            $failed += $passed ? 0 : 1;
        }

        if ($failed > 0) {
            $this->error($failed.' production-readiness check(s) failed.');
            return self::FAILURE;
        }

        $this->info('Production readiness checks passed.');
        return self::SUCCESS;
    }
}
