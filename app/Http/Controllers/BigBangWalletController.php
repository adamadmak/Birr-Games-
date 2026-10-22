<?php

namespace App\Http\Controllers;

use App\Models\BigBangSession;
use App\Models\BigBangTransaction;
use App\Models\Game;
use App\Models\GameRound;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\BigBangCasinoClient;
use App\Services\WageringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BigBangWalletController
{
    public function __construct(
        private readonly WageringService $wagering,
        private readonly BigBangCasinoClient $client,
    ) {
    }

    public function userData(Request $request): JsonResponse
    {
        if (!$this->callbackTokenIsValid($request)) {
            return response()->json(['status' => 'error', 'error' => 'Invalid callback token.'], 401);
        }

        $user = $this->user((string) $request->query('username'));
        if (!$user || !$user->active) {
            return response()->json(['status' => 'error', 'error' => 'Player not found.'], 404);
        }

        /*
         * Only for a player who is actually in a game.
         *
         * This route carries no auth middleware — its only gate is a token that
         * falls through to a literal committed in this file, because the build
         * caches config without the runtime environment. Anyone holding that
         * literal could walk player_1, player_2, ... and read the exact balance
         * of every account on the platform. There is no legitimate caller that
         * needs a balance for a player who is not mid-round, so requiring a
         * live session closes the enumeration while leaving the only real use
         * working: the game asking what its own player has to spend.
         *
         * The route itself goes when the wallet does, once BigBang has finished
         * settling. Until then this is the part that cannot wait.
         */
        if (!$this->hasOpenSession($user)) {
            return response()->json(['status' => 'error', 'error' => 'Player not found.'], 404);
        }

        return response()->json([
            'username' => $this->client->userToken($user),
            'balance' => $this->formatAmount($user->credits),
            'currency' => strtoupper((string) config('services.bigbang.currency', 'ETB')),
        ]);
    }

    public function balanceChange(Request $request): JsonResponse
    {
        if (!$this->callbackTokenIsValid($request)) {
            return response()->json(['status' => 'error', 'error' => 'Invalid callback token.'], 401);
        }

        $payload = $request->json()->all();
        if (($payload['sandbox'] ?? false) === true) {
            return response()->json(['status' => 'ok', 'balance' => '100000.00']);
        }
        if (config('services.bigbang.mode') !== 'live') {
            return response()->json(['status' => 'error', 'error' => 'Live wallet sessions are not enabled.'], 422);
        }
        if (!$this->client->balanceChangeSignatureIsValid($payload)) {
            return response()->json(['status' => 'error', 'error' => 'Invalid callback signature.'], 401);
        }

        $user = $this->user((string) ($payload['username'] ?? ''));
        if (!$user || !$user->active) {
            return response()->json(['status' => 'error', 'error' => 'Player not found.'], 404);
        }

        try {
            return DB::transaction(fn () => $this->applyBalanceChange($user, $payload));
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return response()->json(['status' => 'error', 'error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['status' => 'error', 'error' => 'Wallet transaction could not be completed.'], 500);
        }
    }

    private function applyBalanceChange(User $user, array $payload): JsonResponse
    {
        $user = User::query()->lockForUpdate()->findOrFail($user->id);
        $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
        if ($transactionId === '') {
            throw new \InvalidArgumentException('Transaction ID is required.');
        }

        $delta = $this->wholeEtbDelta($payload['amount'] ?? null);
        if (abs($delta) < 0.005) {
            throw new \InvalidArgumentException('A non-zero ETB amount is required.');
        }

        $action = $delta < 0 ? 'bet' : 'win';
        $amount = abs($delta);
        $payloadHash = $this->payloadHash($payload);

        // Providers reuse one transaction id for both legs of a round (the stake and the
        // settlement), which is why the table is unique on (action, transaction_id).
        // Matching on the id alone rejected every win that shared its bet's id with an
        // HTTP 409, so the stake was taken and the payout never arrived.
        $duplicate = BigBangTransaction::query()
            ->where('provider_transaction_id', $transactionId)
            ->where('action', $action)
            ->first();
        if ($duplicate) {
            if ($duplicate->user_id !== $user->id || !hash_equals($duplicate->payload_hash, $payloadHash)) {
                return response()->json(['status' => 'error', 'error' => 'Transaction ID conflicts with an earlier callback.'], 409);
            }

            return response()->json(['status' => 'ok', 'balance' => $this->formatAmount((float) $duplicate->response_balance)]);
        }

        $game = $this->game((string) ($payload['game'] ?? ''), (string) ($payload['game_category'] ?? ''));
        $session = $this->validSession($user, $game);
        if (!$session) {
            Log::warning('A BigBang wallet callback was refused because no live session matched it.', [
                'user_id' => $user->id,
                'transaction_id' => $transactionId,
                'action' => $action,
                'amount' => $amount,
                'game' => $payload['game'] ?? null,
            ]);

            return response()->json(['status' => 'error', 'error' => 'The provider session is invalid or expired.'], 403);
        }
        if ($action === 'bet' && (float) $user->credits < $amount) {
            return response()->json(['status' => 'error', 'error' => 'Insufficient balance.'], 422);
        }

        $withdrawableDebit = 0.0;
        if ($action === 'bet') {
            $lockedCredits = max(0, (float) $user->credits - (float) $user->withdrawable_balance);
            $withdrawableDebit = max(0, $amount - $lockedCredits);
            $user->credits = (float) $user->credits - $amount;
            $user->withdrawable_balance = max(0, (float) $user->withdrawable_balance - $withdrawableDebit);
        } else {
            $user->credits = (float) $user->credits + $amount;
            $user->withdrawable_balance = (float) $user->withdrawable_balance + $amount;
        }
        $user->save();

        // Every provider stake and settlement is written to game_rounds as well, so a
        // deduction can always be traced to a round in the member profile, the casino
        // report and the player accountability report.
        $round = $this->recordRound($user, $game, $session, $action, $amount, $transactionId);

        BigBangTransaction::create([
            'provider_transaction_id' => $transactionId,
            'action' => $action,
            'user_id' => $user->id,
            'game_id' => $game?->id,
            'game_round_id' => $round?->id,
            'provider_session_id' => $session->external_session_id,
            'amount' => $amount,
            'currency' => strtoupper((string) config('services.bigbang.currency', 'ETB')),
            'response_balance' => $user->credits,
            'payload_hash' => $payloadHash,
            'metadata' => [
                'callback' => $payload,
                'withdrawable_debit' => $withdrawableDebit,
                'game_category' => $payload['game_category'] ?? null,
            ],
        ]);

        WalletTransaction::create([
            'ledger_key' => 'bigbang-'.$action.'-'.$transactionId,
            'user_id' => $user->id,
            'type' => $action === 'win' ? 'payout' : 'bet',
            'amount' => $delta,
            'status' => 'success',
            'method' => 'bigbang',
            'reason' => ($game?->title ? $game->title.' ' : 'BigBang ').($action === 'win' ? 'payout' : 'stake'),
            'currency' => 'ETB',
            'source_type' => 'bigbang_session',
            'source_id' => $session->id,
            'metadata' => [
                'provider_transaction_id' => $transactionId,
                'game_round_id' => $round?->id,
                'game' => $game?->slug,
            ],
        ]);

        if ($action === 'bet') {
            $this->wagering->recordStake($user, $amount);
        }
        $session->update(['last_activity_at' => now()]);

        return response()->json(['status' => 'ok', 'balance' => $this->formatAmount((float) $user->credits)]);
    }

    /**
     * Mirror a provider settlement into game_rounds. A stake opens the round; the
     * settlement is attached to the newest open round of the same session so stake and
     * payout totals always reconcile with the wallet ledger.
     */
    private function recordRound(
        User $user,
        ?Game $game,
        BigBangSession $session,
        string $action,
        float $amount,
        string $transactionId,
    ): ?GameRound {
        $slug = $game?->slug ?: 'bigbang-provider';

        if ($action === 'bet') {
            return GameRound::create([
                'user_id' => $user->id,
                'game' => $slug,
                'provider' => 'bigbang',
                'external_round_id' => $transactionId,
                'provider_session_id' => $session->external_session_id,
                'stake' => $amount,
                'payout' => 0,
                'result' => [
                    'source' => 'bigbang',
                    'transaction_id' => $transactionId,
                    'settled' => false,
                ],
            ]);
        }

        $round = GameRound::query()
            ->where('user_id', $user->id)
            ->where('provider', 'bigbang')
            ->where('provider_session_id', $session->external_session_id)
            ->where('payout', 0)
            ->where('stake', '>', 0)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (! $round) {
            // A settlement with no open stake (free spins, a bonus round, or a stake that
            // predates this tracking) still belongs in the round history.
            return GameRound::create([
                'user_id' => $user->id,
                'game' => $slug,
                'provider' => 'bigbang',
                'external_round_id' => $transactionId,
                'provider_session_id' => $session->external_session_id,
                'stake' => 0,
                'payout' => $amount,
                'result' => [
                    'source' => 'bigbang',
                    'transaction_id' => $transactionId,
                    'settled' => true,
                    'unmatched_settlement' => true,
                ],
            ]);
        }

        $round->payout = (float) $round->payout + $amount;
        $round->result = array_merge($round->result ?? [], [
            'settled' => true,
            'settlement_transaction_id' => $transactionId,
        ]);
        $round->save();

        return $round;
    }

    private function callbackTokenIsValid(Request $request): bool
    {
        $expected = trim((string) config('services.bigbang.callback_token'));

        // Fallback: on this platform the build's `php artisan config:cache` runs without the
        // runtime env, freezing callback_token to null, AND php-fpm does not expose the
        // dashboard env vars to getenv()/env() at runtime — so both config() and the process
        // env come back empty and every callback would 401. Fall back through both, then to the
        // shared token literal as a last resort so seamless wallet callbacks authenticate.
        // TODO: once the deploy caches config WITH env present (or php-fpm clear_env=no), drop
        // the literal and keep this env-only.
        if (strlen($expected) < 32) {
            $expected = trim((string) (getenv('BIGBANG_CALLBACK_TOKEN') ?: env('BIGBANG_CALLBACK_TOKEN')));
        }
        if (strlen($expected) < 32) {
            $expected = 'f2ac413642a81a6d425ce96407aa2d3143ac81dc868bdcbd35dc0e63b48c58a8';
        }

        $provided = trim((string) $request->query('token'));

        return strlen($expected) >= 32 && strlen($provided) === strlen($expected)
            && hash_equals($expected, $provided);
    }

    private function user(string $token): ?User
    {
        $id = (int) preg_replace('/^player_/', '', trim($token));

        return $id > 0 ? User::query()->find($id) : null;
    }

    private function game(string $name, string $provider): ?Game
    {
        if ($name === '') {
            return null;
        }

        return Game::query()
            ->where('sync_source', 'bigbang')
            ->where(function ($query) use ($name): void {
                $query->where('title', $name)
                    ->orWhere('provider_game_id', $name)
                    ->orWhere('external_launch_code', $name);
            })
            ->when($provider !== '', fn ($query) => $query->whereHas(
                'provider',
                fn ($providerQuery) => $providerQuery->where('name', $provider)
            ))
            ->first();
    }

    /**
     * Is this player in a BigBang game right now?
     *
     * Deliberately not validSession(): that one locks the row for a settlement
     * that is about to write. This is a read, on a route that answers before
     * any transaction is open, so it takes no lock and holds nothing.
     */
    private function hasOpenSession(User $user): bool
    {
        return BigBangSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('mode', 'real')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    private function validSession(User $user, ?Game $game): ?BigBangSession
    {
        $idleCutoff = (int) config('services.bigbang.idle_session_minutes', 30);

        return BigBangSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('mode', 'real')
            ->where('currency', strtoupper((string) config('services.bigbang.currency', 'ETB')))
            ->when($game, fn ($query) => $query->where('game_id', $game->id))
            // An unidentified game must not be able to settle against any open session the
            // player happens to have: only a session that is demonstrably still in use.
            ->unless($game, fn ($query) => $query->where('last_activity_at', '>=', now()->subMinutes($idleCutoff)))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    private function wholeEtbDelta(mixed $value): float
    {
        // Accept fractional ETB to 2 decimals. `users.credits` is decimal(18,2), and many
        // BigBang providers legitimately produce fractional wins (paytable coefficient x a
        // whole stake, e.g. 0.5x of 5 = 2.50). Rejecting fractional deltas (the old behaviour)
        // dropped those settlements (HTTP 422) so the local game balance and this wallet drifted.
        // Storing the exact 2dp amount keeps them consistent with no server-side rounding.
        $string = trim((string) $value);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $string)) {
            throw new \InvalidArgumentException(
                'Invalid ETB amount — expected a number with at most 2 decimals.'
            );
        }

        return round((float) $string, 2);
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function payloadHash(array $payload): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (array_is_list($value)) {
                return array_map($normalize, $value);
            }
            ksort($value, SORT_STRING);

            return array_map($normalize, $value);
        };

        return hash('sha256', json_encode(
            $normalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }
}
