<?php

namespace App\Http\Controllers;

use App\Models\BigBangSession;
use App\Models\Game;
use App\Services\BigBangCasinoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ProviderGameController
{
    public function launch(Request $request, Game $game, BigBangCasinoClient $client): View|RedirectResponse
    {
        // One launch route for every aggregator, so the lobby and the game page
        // need no knowledge of which one carries a game. Hand off before the
        // BigBang-specific guard below.
        if ($game->sync_source === 'qtech') {
            return app(QtechGameController::class)->launch($request, $game, app(\App\Services\QtechClient::class));
        }

        abort_unless(
            $game->isAvailable() && $game->playable && $game->content_server_enabled
            && $game->sync_source === 'bigbang' && ($game->external_launch_code || $game->provider_game_id),
            404
        );

        if ($request->user()->isAdmin()) {
            return redirect()->route('games.show', $game)->withErrors(['provider' => 'Administrator accounts are restricted from playing casino games.']);
        }

        try {
            $payload = $client->launch(
                $game,
                $request->user(),
                route('integrations.bigbang.user-data', [
                    'token' => (string) config('services.bigbang.callback_token'),
                ]),
                route('home')
            );
            $launchUrl = $this->validatedLaunchUrl((string) ($payload['launch_url'] ?? ''));
        } catch (Throwable $exception) {
            report($exception);
            // A dropped connection is not a wrong base URL: on 27 Oct 2026 it was
            // BigBang's allow-list not knowing this server's address. Name that,
            // and point at the test button that shows the address to send them.
            $message = match (true) {
                $exception instanceof \Illuminate\Http\Client\ConnectionException
                    => 'BigBang did not answer from this server ('.\App\Services\BigBangCasinoClient::shortCurl($exception->getMessage()).'). An administrator can see the address BigBang need to allow under Configuration → System → Test BigBang connection.',
                $exception instanceof RuntimeException => $exception->getMessage(),
                default => 'BigBang could not be reached. Check the provider API base URL and try again.',
            };
            return redirect()->route('games.show', $game)->withErrors(['provider' => $message]);
        }

        $sessionId = (string) ($payload['session_id'] ?? 'demo_'.Str::uuid());

        // This write can collide with AccountMergeService, which holds row and
        // gap locks on bigbang_sessions while repointing a duplicate player's
        // history. The resulting InnoDB lock-wait timeout (error 1205) is transient
        // — the merge finishes in seconds — so we retry up to three times with a
        // one-second backoff before giving up. The wallet callback REQUIRES this
        // row to exist and be active before it will settle a bet, so a player let
        // into the game without it would place real-money bets that silently fail
        // to settle. Fail the same visible way the BigBang API call above does.
        try {
            $session = retry(3, function () use ($sessionId, $request, $game, $client, $payload) {
                $session = BigBangSession::query()->firstOrNew(['external_session_id' => $sessionId]);
                if ($session->exists && ($session->user_id !== $request->user()->id || $session->game_id !== $game->id)) {
                    report(new RuntimeException('BigBang returned a session identifier already assigned to another launch.'));

                    return null;
                }
                $maximumExpiry = now()->addHours(6);
                $expiresAt = isset($payload['expires_at'])
                    ? \Illuminate\Support\Carbon::parse($payload['expires_at'])
                    : now()->addHours(2);
                if ($expiresAt->isAfter($maximumExpiry)) {
                    $expiresAt = $maximumExpiry;
                }
                $session->fill([
                    'user_id' => $request->user()->id,
                    'game_id' => $game->id,
                    'mode' => $client->sandbox() ? 'sandbox' : 'real',
                    'currency' => strtoupper((string) ($payload['currency'] ?? config('services.bigbang.currency', 'EUR'))),
                    'status' => 'active',
                    'expires_at' => $expiresAt,
                    'last_activity_at' => now(),
                ])->save();

                return $session;
            }, 1000, function (Throwable $e) {
                // Only retry on InnoDB lock wait timeout; every other failure is final.
                return $e instanceof \Illuminate\Database\QueryException
                    && str_contains($e->getMessage(), '1205');
            });

            if ($session === null) {
                return redirect()->route('games.show', $game)
                    ->withErrors(['provider' => 'The provider returned a conflicting session. Please launch the game again.']);
            }
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('games.show', $game)
                ->withErrors(['provider' => 'Could not start this session. Please try again in a moment.']);
        }

        // A player is only ever in one game at a time. Retiring the other open
        // sessions means a stale one can never authorise a later wallet debit — but
        // the session that authorises THIS launch already exists and wins any lookup
        // by recency, so unlike the write above, a failure here is cosmetic (a stale
        // row lingers) rather than something the player needs to be stopped for.
        try {
            BigBangSession::query()
                ->where('user_id', $request->user()->id)
                ->where('status', 'active')
                ->whereKeyNot($session->id)
                ->update(['status' => 'closed', 'updated_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
        }

        return view('games.provider', [
            'game' => $game->load('provider'),
            'launchUrl' => $launchUrl,
            'currency' => strtoupper((string) ($payload['currency'] ?? config('services.bigbang.currency', 'EUR'))),
        ]);
    }

    /**
     * Called when the player leaves the game screen. Closing the session immediately
     * stops the provider from settling anything else against this player's wallet.
     */
    public function closeSession(Request $request): JsonResponse
    {
        $closed = BigBangSession::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->when(
                $request->filled('session_id'),
                fn ($query) => $query->where('external_session_id', $request->string('session_id')->limit(120)->toString())
            )
            ->update(['status' => 'closed', 'updated_at' => now()]);

        // The game frame does not know which aggregator it was showing, so
        // close the player's open QTech sessions on the same signal.
        $closed += \App\Models\QtechSession::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->update(['status' => 'closed', 'updated_at' => now()]);

        return response()->json(['ok' => true, 'closed' => $closed]);
    }

    private function validatedLaunchUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', (array) config('services.bigbang.allowed_launch_hosts', []));

        if (($parts['scheme'] ?? null) !== 'https' || $host === '' || !in_array($host, $allowed, true)) {
            throw new RuntimeException('BigBang returned an unsafe or unexpected launch URL.');
        }

        return $url;
    }
}
