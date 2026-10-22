<?php

namespace App\Http\Controllers;

use App\Services\BigBangCasinoClient;
use App\Services\BigBangCatalogSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Drives the catalog refresh from outside the application.
 *
 * `routes/console.php` schedules `bigbang:sync-catalog` hourly, but Laravel's
 * scheduler does not run on this host — measured 2026-08-10 by disabling a
 * provider the feed still carries and watching it stay disabled straight through
 * the slot, when a sync would have force-enabled it. So the lobby only ever
 * refreshed when an admin clicked Game Engine → Sync, and every change made in
 * the BigBang portal stayed invisible to players until someone did.
 *
 * This endpoint lets any external timer run the same command over HTTP. It is a
 * workaround for the missing scheduler, not a replacement for it: if a real cron
 * or worker is ever wired up on the host, prefer the console command and delete
 * this.
 */
class BigBangCatalogSyncController
{
    /**
     * Shared with Admin\GameEngineController::sync — both entry points drive
     * the same synchronizer, and two concurrent runs interleave their writes.
     */
    public const LOCK_KEY = 'bigbang-catalog-sync';

    public function __invoke(
        Request $request,
        BigBangCasinoClient $client,
        BigBangCatalogSynchronizer $synchronizer
    ): JsonResponse {
        if (!$this->tokenIsValid($request)) {
            return response()->json(['error' => 'Invalid token.'], 401);
        }

        // BigBang is retired. Refused here rather than by deleting the route,
        // so un-retiring it in Settings restores the sync with no deploy.
        if (\App\Support\BigBang::retired()) {
            return response()->json(['error' => 'BigBang has been retired.'], 410);
        }

        if (!$client->configured()) {
            return response()->json(['error' => 'BigBang is not configured.'], 503);
        }

        // A full read takes ~2-3 minutes. Without this, a timer firing again before
        // the previous run finished would have two syncs interleaving their writes,
        // and the loser's half-built view of the feed could retire live games.
        $lock = Cache::lock(self::LOCK_KEY, 900);
        if (!$lock->get()) {
            return response()->json(['skipped' => 'A catalog sync is already running.'], 409);
        }

        try {
            // 45 seconds, hard. This runs inside a web request on a small worker
            // pool, and an unbudgeted run was measured taking the whole site down
            // for its duration — the external timer that calls this fires again
            // anyway, so a truncated run just continues on the next tick.
            $result = $synchronizer->sync(budgetSeconds: 45.0);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => $exception->getMessage()], 502);
        } finally {
            $lock->release();
        }

        return response()->json($result);
    }

    private function tokenIsValid(Request $request): bool
    {
        // Read the same way BigBangWalletController has to: this platform runs
        // `config:cache` at build time without the runtime env, and php-fpm does not
        // expose the dashboard variables to getenv()/env() either, so an env-only
        // secret arrives empty and every call would 401. config() therefore falls
        // through to the literal default in config/services.php.
        $expected = trim((string) config('services.bigbang.sync_token'));
        if (strlen($expected) < 32) {
            $expected = trim((string) (getenv('BIGBANG_SYNC_TOKEN') ?: env('BIGBANG_SYNC_TOKEN')));
        }

        $provided = trim((string) ($request->query('token') ?? $request->input('token')));

        return strlen($expected) >= 32
            && strlen($provided) === strlen($expected)
            && hash_equals($expected, $provided);
    }
}
