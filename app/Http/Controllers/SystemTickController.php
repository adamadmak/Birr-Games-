<?php

namespace App\Http\Controllers;

use App\Support\Qtech;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The scheduler's clock, driven from outside.
 *
 * This host runs no cron and no queue worker, so until 28 Oct 2026 nothing in
 * routes/console.php ever fired: bot password messages promised to vanish in
 * ten minutes stayed in the chat, deposit bonuses never expired, scheduled
 * broadcasts sat in the jobs table, sessions were never expired and the
 * wallet was never reconciled. The external timer that already refreshes the
 * catalogues calls this once a minute instead, with the same sync token, and
 * it runs `schedule:run` — which includes the every-minute queue worker.
 *
 * Guarded the way the catalogue endpoints are: one run at a time under a
 * cache lock, a time budget because it holds one of a handful of serve
 * workers, and a heartbeat the admin can read.
 */
class SystemTickController
{
    public const HEARTBEAT_KEY = 'operations.scheduler_heartbeat';
    public const LAST_TICK_KEY = 'system.tick.last';

    private const LOCK_KEY = 'system-tick';
    private const BUDGET_SECONDS = 90;

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->tokenIsValid($request)) {
            return response()->json(['error' => 'Invalid token.'], 401);
        }

        $lock = Cache::lock(self::LOCK_KEY, self::BUDGET_SECONDS + 30);
        if (! $lock->get()) {
            return response()->json(['skipped' => 'A tick is already running.'], 409);
        }

        $started = microtime(true);
        @set_time_limit(self::BUDGET_SECONDS + 15);
        ignore_user_abort(true);

        try {
            Artisan::call('schedule:run');
            $output = trim(Artisan::output());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => $exception->getMessage()], 500);
        } finally {
            $seconds = round(microtime(true) - $started, 1);
            Cache::forever(self::LAST_TICK_KEY, ['at' => now()->toIso8601String(), 'seconds' => $seconds]);
            $lock->release();
        }

        $ran = substr_count($output, 'Running');

        return response()->json([
            'ok' => true,
            'ran' => $ran,
            'seconds' => $seconds,
            'output' => mb_substr($output, 0, 2000),
        ]);
    }

    /** The same token the catalogue-refresh endpoints take, so the timer carries one credential. */
    private function tokenIsValid(Request $request): bool
    {
        $expected = Qtech::syncToken();
        $provided = trim((string) ($request->query('token') ?? $request->input('token')));

        return strlen($expected) >= 32
            && strlen($provided) === strlen($expected)
            && hash_equals($expected, $provided);
    }
}
