<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What an uptime monitor asks once a minute.
 *
 * Laravel's own /up only proves the process answers. This one says whether
 * the database answers, whether the scheduler has run lately (the tick is
 * called by an outside timer; if that timer stops, bonuses stop expiring and
 * bot password messages stop being deleted, silently), and whether the
 * schema is behind the code. Nothing here names a player, a key or a path.
 */
class SystemHealthController
{
    /** The scheduler is expected every minute; this much silence is a page. */
    private const HEARTBEAT_STALE_SECONDS = 300;

    public function __invoke(): JsonResponse
    {
        $database = $this->databaseAnswers();
        $heartbeat = $this->heartbeatAge();
        $migrations = $database ? $this->migrationsPending() : null;

        $ok = $database && $heartbeat !== null && $heartbeat <= self::HEARTBEAT_STALE_SECONDS && $migrations === false;

        return response()->json([
            'ok' => $ok,
            'database' => $database ? 'ok' : 'down',
            'scheduler' => $heartbeat === null ? 'never' : ($heartbeat <= self::HEARTBEAT_STALE_SECONDS ? 'ok' : 'stale'),
            'scheduler_heartbeat_seconds' => $heartbeat,
            'migrations_pending' => $migrations,
            'checked_at' => now()->toIso8601String(),
        ], $ok ? 200 : 503, [], JSON_PRETTY_PRINT);
    }

    private function databaseAnswers(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function heartbeatAge(): ?int
    {
        try {
            $at = Cache::get(SystemTickController::HEARTBEAT_KEY);

            return $at ? max(0, Carbon::parse($at)->diffInSeconds(now())) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function migrationsPending(): ?bool
    {
        try {
            return app(\App\Services\PendingMigrations::class)->status()['pending'] !== [];
        } catch (\Throwable) {
            return null;
        }
    }
}
