<?php

namespace App\Http\Middleware;

use App\Models\TelegramUpdate;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DeduplicateTelegramUpdate
{
    public function handle(Request $request, Closure $next): Response
    {
        // The secret first: an anonymous caller must not be able to write a
        // row, or claim an update_id so that the real update is dropped.
        if (! app(\App\Services\TelegramBotService::class)->validWebhookSecret((string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return new JsonResponse(['ok' => false], 403);
        }

        $updateId = filter_var($request->input('update_id'), FILTER_VALIDATE_INT);
        if ($updateId === false || $updateId < 0) {
            return new JsonResponse(['ok' => false, 'error' => 'A valid Telegram update_id is required.'], 422);
        }

        $payloadHash = hash('sha256', (string) $request->getContent());
        try {
            $update = TelegramUpdate::query()->firstOrCreate(
                ['update_id' => $updateId],
                ['payload_hash' => $payloadHash, 'status' => 'pending'],
            );
        } catch (QueryException) {
            // The insert lost a race (another delivery of the same update_id got
            // there first) or hit lock contention on this table under a backlog of
            // retries. where()->first() is deliberately NOT firstOrFail(): under the
            // exact contention this guards against, the winning row is not always
            // visible to this connection's snapshot yet, and firstOrFail() turned
            // that into an uncaught ModelNotFoundException — which Laravel renders
            // as a bare 404, which Telegram reads as "give up on this webhook" and
            // which then grows the very backlog that caused the contention. A miss
            // here falls through to processing the update directly: this app's own
            // controller-level update_id cache guard (Cache::add) is what actually
            // has to hold the duplicate-processing line, this table is bookkeeping.
            $update = TelegramUpdate::query()->where('update_id', $updateId)->first();
            if (! $update) {
                return $next($request);
            }
        }

        if (!hash_equals($update->payload_hash, $payloadHash)) {
            Log::critical('Telegram reused an update_id with a different payload.', ['update_id' => $updateId]);

            return new JsonResponse(['ok' => false], 409);
        }
        if ($update->status === 'completed') {
            return new JsonResponse(['ok' => true, 'duplicate' => true]);
        }

        // Everything from here on is bookkeeping about a message that still has to
        // be answered. A lock wait on this table (this middleware writes to it on
        // every single update, so it is exactly as contended as the traffic itself)
        // must never stop that from happening — it has cost the site a working bot
        // before. Claiming is best-effort: a failure here means only that a legitimate
        // retry might get treated as fresh work instead of recognised as one, which
        // is harmless next to leaving the player's message unanswered entirely.
        $staleBefore = now()->subMinutes(2);
        try {
            $claimed = TelegramUpdate::query()
                ->whereKey($update->id)
                ->where(function ($query) use ($staleBefore): void {
                    $query->whereIn('status', ['pending', 'failed'])
                        ->orWhere(function ($processing) use ($staleBefore): void {
                            $processing->where('status', 'processing')
                                ->where('updated_at', '<=', $staleBefore);
                        });
                })
                ->update([
                    'status' => 'processing',
                    'error' => null,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                return new JsonResponse(['ok' => true, 'processing' => true]);
            }

            $update->refresh();
        } catch (QueryException $exception) {
            report($exception);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            try {
                $update->update([
                    'status' => 'failed',
                    'error' => str($exception->getMessage())->limit(1000)->toString(),
                ]);
            } catch (QueryException $trackingException) {
                report($trackingException);
            }

            throw $exception;
        }

        try {
            if ($response->getStatusCode() >= 500) {
                $update->update(['status' => 'failed', 'error' => 'HTTP '.$response->getStatusCode()]);
            } else {
                $update->update(['status' => 'completed', 'error' => null, 'processed_at' => now()]);
            }
        } catch (QueryException $exception) {
            report($exception);
        }

        return $response;
    }
}
