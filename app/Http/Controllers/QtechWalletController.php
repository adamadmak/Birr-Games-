<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameRound;
use App\Models\QtechSession;
use App\Models\QtechTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WageringService;
use App\Support\Qtech;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The QTech (QT) Common Wallet endpoints.
 *
 * QTech host the games; this wallet holds the money. Every bet, win and refund
 * arrives here as an HTTP callback authenticated by a shared Pass-Key header.
 *
 * Three rules run through the whole class, all of them from QT's spec:
 *
 *  - Idempotency. QT abandon a call after 3s connect / 5s read and retry it.
 *    A retry must return the ORIGINAL response, not move money again, so every
 *    transactional method looks for its txnId first and replays the stored
 *    answer if it finds one.
 *
 *  - Sessions expire, money does not. Only a withdrawal needs a live session.
 *    Deposits and rollbacks must work with an expired or missing one, because
 *    QT resolve unfinished rounds long after a player has closed the game —
 *    refusing there would strand a payout the game already awarded.
 *
 *  - Forward compatibility. QT add payload fields without notice and the spec
 *    asks integrators to ignore unknown ones, so nothing here validates the
 *    payload shape beyond the fields it actually reads.
 */
class QtechWalletController
{
    /** gameId -> local slug, for this request only. */
    private static array $slugCache = [];

    public function __construct(private readonly WageringService $wagering)
    {
    }

    /* ---------------------------------------------------------------- */
    /* Verify Session — GET accounts/{playerId}/session?gameId=          */
    /* ---------------------------------------------------------------- */

    public function verifySession(Request $request, string $playerId): JsonResponse
    {
        if (! $this->passKeyIsValid($request)) {
            return $this->fail('LOGIN_FAILED', 'The given pass key is missing or invalid.', 401);
        }

        $user = $this->player($playerId);
        if (! $user) {
            return $this->fail('INVALID_TOKEN', 'Unknown player.', 400);
        }

        $session = $this->session($request);
        if (! $session || $session->user_id !== $user->id || ! $session->isLive()) {
            return $this->fail('INVALID_TOKEN', 'The given wallet session token is missing, invalid or expired.', 400);
        }

        if ($this->isBlocked($user)) {
            return $this->fail('ACCOUNT_BLOCKED', 'The player account is blocked.', 403);
        }

        $session->forceFill(['last_activity_at' => now()])->save();

        return response()->json([
            'balance' => $this->money($user->credits),
            'currency' => Qtech::currency(),
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Get Balance — GET accounts/{playerId}/balance?gameId=             */
    /* ---------------------------------------------------------------- */

    /**
     * Both the session header and the gameId are optional here, per spec, and
     * an expired session must still answer: the game polls this to refresh the
     * number on screen, and a stale token is not a reason to hide a balance.
     */
    public function getBalance(Request $request, string $playerId): JsonResponse
    {
        if (! $this->passKeyIsValid($request)) {
            return $this->fail('LOGIN_FAILED', 'The given pass key is missing or invalid.', 401);
        }

        $user = $this->player($playerId);
        if (! $user) {
            return $this->fail('INVALID_TOKEN', 'Unknown player.', 400);
        }

        if ($this->isBlocked($user)) {
            return $this->fail('ACCOUNT_BLOCKED', 'The player account is blocked.', 403);
        }

        return response()->json([
            'balance' => $this->money($user->credits),
            'currency' => Qtech::currency(),
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Withdrawal (DEBIT) and Deposit (CREDIT) — POST transactions       */
    /* ---------------------------------------------------------------- */

    /**
     * One endpoint for both, because QT post both to the same URL and
     * distinguish them by txnType. Splitting them by route would mean trusting
     * the URL over the payload for something that decides which way money
     * moves.
     */
    public function transaction(Request $request): JsonResponse
    {
        if (! $this->passKeyIsValid($request)) {
            return $this->fail('LOGIN_FAILED', 'The given pass key is missing or invalid.', 401);
        }

        $payload = $request->json()->all();
        $type = strtoupper(trim((string) ($payload['txnType'] ?? '')));

        return match ($type) {
            QtechTransaction::TYPE_DEBIT => $this->withdraw($request, $payload),
            QtechTransaction::TYPE_CREDIT => $this->deposit($request, $payload),
            default => $this->fail('REQUEST_DECLINED', 'Unsupported txnType.', 400),
        };
    }

    private function withdraw(Request $request, array $payload): JsonResponse
    {
        $txnId = trim((string) ($payload['txnId'] ?? ''));
        if ($txnId === '') {
            return $this->fail('REQUEST_DECLINED', 'txnId is required.', 400);
        }

        $user = $this->player((string) ($payload['playerId'] ?? ''));
        if (! $user) {
            return $this->fail('REQUEST_DECLINED', 'Unknown player.', 400);
        }

        // Before the session check: a retry of a bet we already took must
        // replay, even if the session has since lapsed. Otherwise QT's own
        // retry of a debit we accepted comes back INVALID_TOKEN and the round
        // is left unresolvable.
        if ($replay = $this->replay(QtechTransaction::TYPE_DEBIT, $txnId, $payload, $user)) {
            return $replay;
        }

        $session = $this->session($request);
        if (! $session || $session->user_id !== $user->id || ! $session->isLive()) {
            return $this->fail('INVALID_TOKEN', 'The given wallet session token is missing, invalid or expired.', 400);
        }

        if ($this->isBlocked($user)) {
            return $this->fail('ACCOUNT_BLOCKED', 'The player account is blocked.', 403);
        }

        $amount = $this->amount($payload['amount'] ?? 0);
        if ($amount < 0) {
            return $this->fail('REQUEST_DECLINED', 'A negative amount cannot be debited.', 400);
        }

        try {
            return DB::transaction(function () use ($user, $amount, $txnId, $payload, $session) {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);

                // Re-checked inside the lock: two bets racing for the last of a
                // balance must not both read it as available.
                if ($amount > 0 && (float) $locked->credits < $amount) {
                    return $this->fail('INSUFFICIENT_FUNDS', 'Not enough funds for the debit operation.', 400);
                }

                $withdrawableDebit = 0.0;
                if ($amount > 0) {
                    // Mirror the other integrations: spend locked bonus credit
                    // before money the player could have withdrawn, so placing
                    // a bet never quietly converts one into the other.
                    $lockedCredits = max(0, (float) $locked->credits - (float) $locked->withdrawable_balance);
                    $withdrawableDebit = max(0, $amount - $lockedCredits);
                    $locked->credits = round((float) $locked->credits - $amount, 2);
                    $locked->withdrawable_balance = round(max(0, (float) $locked->withdrawable_balance - $withdrawableDebit), 2);
                    $locked->save();
                }

                $row = $this->record(QtechTransaction::TYPE_DEBIT, $txnId, $locked, $amount, $payload, [
                    'withdrawable_debit' => $withdrawableDebit,
                ]);

                // Only a real movement reaches the money ledger. A 0-amount
                // debit is QT's free-round marker: it opens a round without
                // charging, and qtech_transactions already records that.
                if ($amount > 0) {
                    // Remember how much of this stake was bonus, so the round's payout
                    // returns that part to the bonus wallet instead of making it cash.
                    \App\Services\BonusPlay::stake($locked, 'qtech', (string) $row->round_id, $amount, $withdrawableDebit);
                    $this->ledger($locked, $row, 'bet', -$amount, 'QTech bet');
                    $this->wagering->recordStake($locked, $amount);
                }

                $session->forceFill(['last_activity_at' => now()])->save();

                return $this->settled($row, 201);
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail('UNKNOWN_ERROR', 'The withdrawal could not be processed.', 500);
        }
    }

    /**
     * A win. Deliberately tolerant: no session requirement, and a zero amount
     * is a valid "round closed, nothing won" marker that QT send to settle a
     * losing round, so it must be accepted rather than rejected as empty.
     */
    private function deposit(Request $request, array $payload): JsonResponse
    {
        $txnId = trim((string) ($payload['txnId'] ?? ''));
        if ($txnId === '') {
            return $this->fail('REQUEST_DECLINED', 'txnId is required.', 400);
        }

        $user = $this->player((string) ($payload['playerId'] ?? ''));
        if (! $user) {
            return $this->fail('REQUEST_DECLINED', 'Unknown player.', 400);
        }

        if ($replay = $this->replay(QtechTransaction::TYPE_CREDIT, $txnId, $payload, $user)) {
            return $replay;
        }

        $amount = $this->amount($payload['amount'] ?? 0);
        if ($amount < 0) {
            return $this->fail('REQUEST_DECLINED', 'A negative amount cannot be credited.', 400);
        }

        try {
            return DB::transaction(function () use ($user, $amount, $txnId, $payload) {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);

                $bonusKept = 0.0;
                if ($amount > 0) {
                    // Winnings are real money the moment they land, so they raise the
                    // withdrawable balance. The part of the payout that is only the
                    // bonus stake coming back stays play-only: bonus is never cash.
                    $bonusKept = \App\Services\BonusPlay::payout($locked, 'qtech', trim((string) ($payload['roundId'] ?? '')), $amount);
                    $locked->credits = round((float) $locked->credits + $amount, 2);
                    $locked->withdrawable_balance = round((float) $locked->withdrawable_balance + $amount - $bonusKept, 2);
                    $locked->save();
                }

                $row = $this->record(QtechTransaction::TYPE_CREDIT, $txnId, $locked, $amount, $payload, $bonusKept > 0 ? ['bonus_kept' => $bonusKept] : []);

                if ($amount > 0) {
                    $this->ledger($locked, $row, 'payout', $amount, 'QTech win');
                }

                return $this->settled($row, 201);
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail('UNKNOWN_ERROR', 'The deposit could not be processed.', 500);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Rollback — POST transactions/rollback                             */
    /* ---------------------------------------------------------------- */

    /**
     * Refund a bet QT have decided never happened.
     *
     * The spec is explicit that a bet we have no record of is a SUCCESS, not a
     * 404: from QT's side a transaction that does not exist here is
     * indistinguishable from one already rolled back. Answering with an error
     * would leave them retrying a refund forever.
     */
    public function rollback(Request $request): JsonResponse
    {
        if (! $this->passKeyIsValid($request)) {
            return $this->fail('LOGIN_FAILED', 'The given pass key is missing or invalid.', 401);
        }

        $payload = $request->json()->all();
        $txnId = trim((string) ($payload['txnId'] ?? ''));
        $betId = trim((string) ($payload['betId'] ?? ''));

        if ($txnId === '') {
            return $this->fail('REQUEST_DECLINED', 'txnId is required.', 400);
        }

        $user = $this->player((string) ($payload['playerId'] ?? ''));
        if (! $user) {
            return $this->fail('REQUEST_DECLINED', 'Unknown player.', 400);
        }

        if ($replay = $this->replay(QtechTransaction::TYPE_ROLLBACK, $txnId, $payload, $user)) {
            return $replay;
        }

        try {
            return DB::transaction(function () use ($user, $txnId, $betId, $payload) {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);

                $bet = $betId === '' ? null : QtechTransaction::query()
                    ->where('type', QtechTransaction::TYPE_DEBIT)
                    ->where('txn_id', $betId)
                    ->lockForUpdate()
                    ->first();

                // Nothing to undo: unknown bet, or one already refunded by an
                // earlier rollback carrying a different txnId. Both answer with
                // the balance and no referenceId, which is what the spec calls
                // a successful no-op.
                if (! $bet || $bet->rolled_back) {
                    return response()->json(['balance' => $this->money($locked->credits)]);
                }

                $amount = (float) $bet->amount;
                if ($amount > 0) {
                    $meta = $bet->metadata ?? [];
                    // Put the money back the way it came out, so a bet paid
                    // for with bonus credit is refunded as bonus credit.
                    $withdrawable = (float) ($meta['withdrawable_debit'] ?? 0);
                    $locked->credits = round((float) $locked->credits + $amount, 2);
                    $locked->withdrawable_balance = round((float) $locked->withdrawable_balance + $withdrawable, 2);
                    $locked->save();
                    \App\Services\BonusPlay::unstake($locked, 'qtech', (string) $bet->round_id, $amount, $withdrawable);
                }

                $bet->forceFill(['rolled_back' => true])->save();

                $row = $this->record(QtechTransaction::TYPE_ROLLBACK, $txnId, $locked, $amount, $payload, [
                    'rolled_back_bet_id' => $bet->txn_id,
                ]);

                if ($amount > 0) {
                    $this->ledger($locked, $row, 'refund', $amount, 'QTech rollback');
                }

                return $this->settled($row, 200);
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail('UNKNOWN_ERROR', 'The rollback could not be processed.', 500);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Rewards — POST bonus/rewards                                      */
    /* ---------------------------------------------------------------- */

    /** Tournament and campaign prizes, awarded outside any game round. */
    public function reward(Request $request): JsonResponse
    {
        if (! $this->passKeyIsValid($request)) {
            return $this->fail('LOGIN_FAILED', 'The given pass key is missing or invalid.', 401);
        }

        $payload = $request->json()->all();
        $txnId = trim((string) ($payload['txnId'] ?? ''));
        $rewardType = trim((string) ($payload['rewardType'] ?? ''));

        if ($txnId === '' || $rewardType === '') {
            return $this->fail('REQUEST_DECLINED', 'rewardType and txnId are required.', 400);
        }

        $user = $this->player((string) ($payload['playerId'] ?? ''));
        if (! $user) {
            return $this->fail('REQUEST_DECLINED', 'Unknown player.', 400);
        }

        if ($replay = $this->replay(QtechTransaction::TYPE_REWARD, $txnId, $payload, $user)) {
            return $replay;
        }

        $amount = $this->amount($payload['amount'] ?? 0);
        if ($amount < 0) {
            return $this->fail('REQUEST_DECLINED', 'A negative amount cannot be rewarded.', 400);
        }

        try {
            return DB::transaction(function () use ($user, $amount, $txnId, $payload, $rewardType) {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);

                if ($amount > 0) {
                    $locked->credits = round((float) $locked->credits + $amount, 2);
                    $locked->withdrawable_balance = round((float) $locked->withdrawable_balance + $amount, 2);
                    $locked->save();
                }

                $row = $this->record(QtechTransaction::TYPE_REWARD, $txnId, $locked, $amount, $payload);

                if ($amount > 0) {
                    $this->ledger($locked, $row, 'payout', $amount, 'QTech '.$rewardType);
                }

                return $this->settled($row, 201);
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail('UNKNOWN_ERROR', 'The reward could not be processed.', 500);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Promotion Status — POST bonus/status                              */
    /* ---------------------------------------------------------------- */

    /**
     * Informative only: QT tell us a free-round promotion changed state. There
     * is no wallet session and no money involved, so this records the fact and
     * acknowledges.
     */
    public function promotionStatus(Request $request): JsonResponse
    {
        if (! $this->passKeyIsValid($request)) {
            return $this->fail('LOGIN_FAILED', 'The given pass key is missing or invalid.', 401);
        }

        Log::info('QTech promotion status callback.', ['payload' => $request->json()->all()]);

        return response()->json(['status' => 'OK']);
    }

    /* ---------------------------------------------------------------- */

    /**
     * Replay a transaction we have already settled.
     *
     * The txnId is the whole idempotency key, and the spec is unconditional
     * about it: "a retry must always return the original response". So a
     * second arrival replays the stored balance and referenceId verbatim
     * rather than re-deciding anything — QT compare the two responses and
     * treat any difference as a broken integration.
     *
     * That holds even when the rest of the payload has changed. Replaying an
     * answer we already gave cannot move money twice, whereas processing a
     * mismatched retry can, so the mismatch is logged for investigation and
     * the original answer still wins. A different PLAYER on the same id is the
     * one case refused outright: replaying there would hand one player another
     * player's balance, which is a disclosure rather than a duplicate.
     */
    private function replay(string $type, string $txnId, array $payload, User $user): ?JsonResponse
    {
        $existing = QtechTransaction::query()->where('type', $type)->where('txn_id', $txnId)->first();

        if (! $existing) {
            return null;
        }

        if ($existing->user_id !== $user->id) {
            Log::warning('QTech transaction id reused for a different player.', [
                'type' => $type,
                'txn_id' => $txnId,
                'original_user_id' => $existing->user_id,
                'requested_user_id' => $user->id,
            ]);

            return $this->fail('REQUEST_DECLINED', 'Transaction id conflicts with an earlier request.', 400);
        }

        if (! hash_equals($existing->payload_hash, $this->payloadHash($payload))) {
            Log::warning('QTech replayed a transaction id with a changed payload; answering with the original response.', [
                'type' => $type,
                'txn_id' => $txnId,
            ]);
        }

        return $this->settled($existing, $type === QtechTransaction::TYPE_ROLLBACK ? 200 : 201);
    }

    private function record(string $type, string $txnId, User $user, float $amount, array $payload, array $extra = []): QtechTransaction
    {
        $row = $this->transactionRow($type, $txnId, $user, $amount, $payload, $extra);

        $this->round($row, $user, $payload);

        return $row;
    }

    /**
     * Mirror the movement into `game_rounds` — the table the casino reads.
     *
     * The wallet ledger records money; this records PLAY, and they are not the
     * same thing. Admin Rounds, the Casino Report's bet, payout and GGR
     * figures, the dashboard, tournaments and "recently played" all read
     * `game_rounds`, and every one of them was written when BigBang was the
     * only aggregator that wrote to it. Without this QTech settles perfectly
     * and the operator looks at an empty casino.
     *
     * QTech name the round on every call, so a round is keyed by their own
     * roundId instead of being reconstructed from "the latest open stake" the
     * way BigBang's had to be. Debits add stake, credits and rewards add
     * payout, a rollback takes the stake back off. Every caller holds the
     * player's row lock, so two calls in the same round cannot race here.
     *
     * Reporting must never cost a player their bet, so this cannot throw: a
     * failure is reported and the settlement still commits without it.
     */
    private function round(QtechTransaction $row, User $user, array $payload): void
    {
        try {
            $roundId = trim((string) ($payload['roundId'] ?? ''));
            // A reward belongs to no round. Key it by its own transaction so it
            // still appears rather than vanishing from the reports entirely.
            $key = $roundId !== '' ? $roundId : 'txn:'.$row->txn_id;

            $amount = (float) $row->amount;
            $stake = match ($row->type) {
                QtechTransaction::TYPE_DEBIT => $amount,
                QtechTransaction::TYPE_ROLLBACK => -$amount,
                default => 0.0,
            };
            $payout = match ($row->type) {
                QtechTransaction::TYPE_CREDIT, QtechTransaction::TYPE_REWARD => $amount,
                default => 0.0,
            };

            // QTech send `completed` as a real boolean or as the string "true".
            $completed = filter_var($payload['completed'] ?? true, FILTER_VALIDATE_BOOL);
            $settled = $row->type !== QtechTransaction::TYPE_DEBIT && $completed;

            $round = GameRound::query()
                ->where('user_id', $user->id)
                ->where('provider', 'qtech')
                ->where('external_round_id', $key)
                ->first();

            if (! $round) {
                GameRound::create([
                    'user_id' => $user->id,
                    'game' => $this->gameSlug($payload),
                    'provider' => 'qtech',
                    'external_round_id' => $key,
                    'provider_session_id' => $this->walletSessionId(),
                    // A rollback or a credit with no preceding debit is a real
                    // case — a free spin, a bonus round, or a stake taken
                    // before this tracking existed. Never a negative stake.
                    'stake' => max(0.0, $stake),
                    'payout' => $payout,
                    'result' => [
                        'source' => 'qtech',
                        'game_id' => trim((string) ($payload['gameId'] ?? '')),
                        'transaction_id' => $row->txn_id,
                        'settled' => $settled,
                    ],
                ]);

                return;
            }

            $round->stake = max(0.0, (float) $round->stake + $stake);
            $round->payout = (float) $round->payout + $payout;
            $round->result = array_merge($round->result ?? [], [
                'transaction_id' => $row->txn_id,
                'settled' => $settled || ($round->result['settled'] ?? false),
            ]);
            $round->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The local slug for QTech's game id — what the reports group and link by.
     *
     * Memoised for the request: a settlement resolves the same game twice, and
     * this sits on the money path.
     */
    private function gameSlug(array $payload): string
    {
        $gameId = trim((string) ($payload['gameId'] ?? ''));
        if ($gameId === '') {
            return 'qtech-provider';
        }

        if (! array_key_exists($gameId, self::$slugCache)) {
            self::$slugCache[$gameId] = (string) (Game::query()
                ->where('sync_source', 'qtech')
                ->where(fn ($query) => $query
                    ->where('external_launch_code', $gameId)
                    ->orWhere('provider_game_id', $gameId))
                ->value('slug') ?: 'qtech-provider');
        }

        return self::$slugCache[$gameId];
    }

    /** The token QTech quoted on this callback, for tying a round to its session. */
    private function walletSessionId(): ?string
    {
        $token = trim((string) (request()?->header('Wallet-Session') ?? ''));

        return $token !== '' ? Str::limit($token, 120, '') : null;
    }

    private function transactionRow(string $type, string $txnId, User $user, float $amount, array $payload, array $extra = []): QtechTransaction
    {
        return QtechTransaction::create([
            'txn_id' => $txnId,
            'type' => $type,
            'user_id' => $user->id,
            'round_id' => $payload['roundId'] ?? null,
            'bet_id' => $payload['betId'] ?? null,
            'amount' => $amount,
            'currency' => strtoupper((string) ($payload['currency'] ?? Qtech::currency())),
            'response_balance' => round((float) $user->credits, 2),
            'payload_hash' => $this->payloadHash($payload),
            'metadata' => $extra + ['callback' => $payload],
        ]);
    }

    /** Mirror the movement into the operator's own ledger, for reconciliation. */
    private function ledger(User $user, QtechTransaction $row, string $type, float $delta, string $reason): void
    {
        WalletTransaction::create([
            'ledger_key' => 'qtech-'.strtolower($row->type).'-'.$row->txn_id,
            'user_id' => $user->id,
            'type' => $type,
            'amount' => $delta,
            'status' => 'success',
            'method' => 'qtech',
            'reason' => $reason,
            'currency' => Qtech::currency(),
            'source_type' => 'qtech_transaction',
            'source_id' => $row->getKey(),
            'metadata' => [
                'txn_id' => $row->txn_id,
                'round_id' => $row->round_id,
                'bet_id' => $row->bet_id,
            ],
        ]);
    }

    private function settled(QtechTransaction $row, int $status): JsonResponse
    {
        return response()->json([
            'balance' => $this->money($row->response_balance),
            'referenceId' => $row->referenceId(),
        ], $status);
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }

    /**
     * Which environment this request arrived on.
     *
     * QTech will not register the same Base URL for staging and production, so
     * the staging routes carry their own prefix and set this default. Anything
     * without it is production — an unrecognised route can only ever be held
     * to the stricter key, never the looser one.
     */
    private function environment(Request $request): string
    {
        // Read off the route NAME rather than a route default: a group cannot
        // carry defaults, and the name is set in one place next to the prefix
        // it belongs to, so the two cannot drift apart.
        $name = (string) $request->route()?->getName();

        return str_starts_with($name, 'integrations.qtech-staging.')
            ? Qtech::STAGING
            : Qtech::PRODUCTION;
    }

    private function passKeyIsValid(Request $request): bool
    {
        $expected = Qtech::passKey($this->environment($request));

        // Fail closed. An unconfigured pass-key must authenticate nobody — and
        // because the two environments resolve different keys, an unset staging
        // key refuses staging without ever falling back to the production one.
        if ($expected === '') {
            return false;
        }

        $provided = trim((string) $request->header('Pass-Key'));

        return $provided !== '' && hash_equals($expected, $provided);
    }

    private function session(Request $request): ?QtechSession
    {
        $token = trim((string) $request->header('Wallet-Session'));

        return $token === '' ? null : QtechSession::query()->where('external_session_id', $token)->first();
    }

    /**
     * QT echo back whatever player id we handed them at game launch. Accept
     * the `player_<id>` form the other integrations use, a bare id, or a
     * username, so the launcher is free to pick whichever reads best.
     */
    private function player(string $playerId): ?User
    {
        $playerId = trim($playerId);
        if ($playerId === '') {
            return null;
        }

        if (preg_match('/^player_(\d+)$/i', $playerId, $matches)) {
            return User::query()->find((int) $matches[1]);
        }

        if (ctype_digit($playerId)) {
            return User::query()->find((int) $playerId);
        }

        return User::query()->where('username', $playerId)->first();
    }

    private function isBlocked(User $user): bool
    {
        return ! $user->active || $user->trashed();
    }

    private function amount(mixed $value): float
    {
        return round((float) $value, 2);
    }

    /** Always a JSON number — QT's verifier rejects a balance sent as a string. */
    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    /**
     * Fingerprint of the fields that decide what a transaction does.
     *
     * Deliberately not the whole payload: QT add fields over time and the spec
     * asks integrators to ignore unknown ones, so hashing everything would
     * turn a harmless new field on a retry into a false conflict.
     */
    private function payloadHash(array $payload): string
    {
        $material = [
            'txnType' => $payload['txnType'] ?? null,
            'txnId' => $payload['txnId'] ?? null,
            'playerId' => $payload['playerId'] ?? null,
            'roundId' => $payload['roundId'] ?? null,
            'betId' => $payload['betId'] ?? null,
            'amount' => isset($payload['amount']) ? round((float) $payload['amount'], 2) : null,
            'currency' => $payload['currency'] ?? null,
        ];

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }
}
