<?php

namespace App\Http\Controllers;

use App\Services\QtechCatalogSynchronizer;
use App\Services\QtechClient;
use App\Support\Qtech;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Drives the QTech catalog refresh from outside the application — the same
 * arrangement as BigBangCatalogSyncController, for the same reason: Laravel's
 * scheduler does not run on this host, so an external timer calls this over
 * HTTP instead. Prefer the console command if a real cron is ever wired up.
 */
class QtechCatalogSyncController
{
    /** Shared with Admin\GameEngineController::syncQtech, which drives the same synchronizer. */
    public const LOCK_KEY = 'qtech-catalog-sync';

    public function __invoke(Request $request, QtechClient $client, QtechCatalogSynchronizer $synchronizer): JsonResponse
    {
        if (! $this->tokenIsValid($request)) {
            return response()->json(['error' => 'Invalid token.'], 401);
        }

        if (! $client->configured()) {
            return response()->json(['error' => 'QTech is not configured.'], 503);
        }

        $lock = Cache::lock(self::LOCK_KEY, 900);
        if (! $lock->get()) {
            return response()->json(['skipped' => 'A QTech catalog sync is already running.'], 409);
        }

        try {
            // Budgeted: this runs inside a web request on a small worker pool.
            // The timer fires again anyway, so a truncated run just continues.
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
        $expected = Qtech::syncToken();
        if ($expected === '') {
            return false;
        }

        $provided = trim((string) ($request->bearerToken() ?? $request->input('token', '')));

        return $provided !== '' && hash_equals($expected, $provided);
    }
}
