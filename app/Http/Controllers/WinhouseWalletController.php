<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WinhouseSession;
use App\Models\WinhouseTransaction;
use App\Services\Wallet\InsufficientFunds;
use App\Services\Wallet\LedgerEntry;
use App\Services\Wallet\PlayerWallet;
use App\Support\Winhouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Seamless wallet for the embedded WinHouse sportsbook.
 *
 * Deliberately mirrors BigBangWalletController: same callback-token gate, same
 * HMAC payload signature, same row lock + idempotency-by-transaction-id, same
 * conflict answer when an id is replayed with a different payload. Two
 * integrations that behave differently under retries is how money goes missing,
 * so the differences here are only where the domain genuinely differs:
 *
 *   - amounts are decimal ETB, not whole units (users.credits is decimal(18,2)),
 *     because an odds-based payout is legitimately fractional;
 *   - there is no game/round, there is a ticket id;
 *   - there is a third action, `void`, for a cancelled or void-settled ticket.
 *
 * Everything is server-to-server. Nothing here trusts the browser.
 */
class WinhouseWalletController
{
    public function __construct(
        private readonly PlayerWallet $wallet,
    ) {
    }

    /** Balance lookup, called when the book opens and after each settlement. */
    public function userData(Request $request): JsonResponse
    {
        if (!$this->callbackTokenIsValid($request)) {
            return response()->json(['status' => 'error', 'error' => 'Invalid callback token.'], 401);
        }

        $user = $this->user((string) $request->query('username'));
        if (!$user || !$user->active) {
            return response()->json(['status' => 'error', 'error' => 'Player not found.'], 404);
        }

        return response()->json([
            'username' => 'player_'.$user->id,
            'balance' => $this->formatAmount($user->credits),
            'currency' => Winhouse::currency(),
        ]);
    }

    /** Bet, win or void. Negative amount debits, positive credits. */
    public function balanceChange(Request $request): JsonResponse
    {
        if (!$this->callbackTokenIsValid($request)) {
            return response()->json(['status' => 'error', 'error' => 'Invalid callback token.'], 401);
        }

        $payload = $request->json()->all();

        // Sandbox short-circuits before any signature or balance work so the
        // book can be integration-tested without touching a real wallet.
        if (($payload['sandbox'] ?? false) === true) {
            return response()->json(['status' => 'ok', 'balance' => '100000.00', 'sandbox' => true]);
        }
        if (!Winhouse::isLive()) {
            return response()->json(['status' => 'error', 'error' => 'Live wallet sessions are not enabled.'], 422);
        }
        if (!$this->signatureIsValid($payload)) {
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


    /**
     * Transaction lookup, for resolving an in-doubt call.
     *
     * When a balance-change times out the caller cannot know whether it applied.
     * Retrying is safe for a payout (idempotent, and the player is owed it
     * either way) but NOT for a stake: if it never applied, a blind retry would
     * charge a player for a bet that was refused. So the sweeper asks instead.
     *
     * Read-only by construction — it can only report, never move money.
     */
    public function transaction(Request $request): JsonResponse
    {
        if (!$this->callbackTokenIsValid($request)) {
            return response()->json(['status' => 'error', 'error' => 'Invalid callback token.'], 401);
        }

        $id = trim((string) $request->query('transaction_id'));
        $action = trim((string) $request->query('action'));
        if ($id === '') {
            return response()->json(['status' => 'error', 'error' => 'transaction_id is required.'], 422);
        }

        $query = WinhouseTransaction::query()->where('provider_transaction_id', $id);
        if ($action !== '') {
            $query->where('action', $action);
        }
        $row = $query->first();

        if (!$row) {
            return response()->json(['status' => 'ok', 'found' => false, 'applied' => false]);
        }

        return response()->json([
            'status' => 'ok',
            'found' => true,
            'applied' => true,
            'action' => $row->action,
            'amount' => number_format((float) $row->amount, 2, '.', ''),
            'balance' => number_format((float) $row->response_balance, 2, '.', ''),
            'ticket_id' => $row->ticket_id,
            'applied_at' => optional($row->created_at)->toIso8601String(),
        ]);
    }

    private function applyBalanceChange(User $user, array $payload): JsonResponse
    {
        // Lock the row for the whole transaction: two concurrent settlements on
        // the same player must not read the same starting balance.
        $user = $this->wallet->lock($user);

        $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
        if ($transactionId === '') {
            throw new \InvalidArgumentException('Transaction ID is required.');
        }

        $delta = $this->decimalDelta($payload['amount'] ?? null);
        if (abs($delta) < 0.005) {
            throw new \InvalidArgumentException('A non-zero amount is required.');
        }
        $action = $this->action($payload, $delta);
        $payloadHash = $this->payloadHash($payload);

        // Idempotency. A repeat of the same (action, id) returns the ORIGINAL
        // balance rather than applying again; the same id carrying a different
        // payload is a bug or an attack, so it is refused rather than guessed at.
        $duplicate = WinhouseTransaction::query()
            ->where('action', $action)
            ->where('provider_transaction_id', $transactionId)
            ->first();
        if ($duplicate) {
            if ($duplicate->user_id !== $user->id || !hash_equals($duplicate->payload_hash, $payloadHash)) {
                return response()->json([
                    'status' => 'error',
                    'error' => 'Transaction ID conflicts with an earlier callback.',
                ], 409);
            }

            return response()->json([
                'status' => 'ok',
                'balance' => $this->formatAmount($duplicate->response_balance),
                'duplicate' => true,
            ]);
        }

        $session = $this->validSession($user, (string) ($payload['session'] ?? ''));
        if (!$session) {
            return response()->json(['status' => 'error', 'error' => 'The book session is invalid or expired.'], 403);
        }

        $amount = abs($delta);
        // A sportsbook ticket is the round: its bet, win and void all name it.
        $ticketRef = trim((string) ($payload['ticket_id'] ?? ''));
        $roundRef = $ticketRef !== '' ? 'ticket:'.$ticketRef : '';
        $currency = Winhouse::currency();
        $entry = new LedgerEntry(
            method: 'winhouse',
            reason: 'Sportsbook '.$action,
            key: 'winhouse-'.$action.'-'.$transactionId,
            sourceType: 'winhouse_session',
            sourceId: $session->id,
            metadata: [
                'provider_transaction_id' => $transactionId,
                'ticket_id' => $payload['ticket_id'] ?? null,
            ],
        );

        // The wallet rules — bonus credit spent first, a win raising the cash
        // balance by everything but the bonus stake coming back, the ledger
        // row, the wagering credit — live in PlayerWallet. A void comes back
        // as a positive amount and is measured like a win: the bonus part of
        // the stake returns as bonus.
        try {
            $move = $delta < 0
                ? $this->wallet->stake($user, $amount, 'winhouse', $roundRef, $entry)
                : $this->wallet->payout($user, $amount, 'winhouse', $roundRef, $entry);
        } catch (InsufficientFunds) {
            return response()->json(['status' => 'error', 'error' => 'Insufficient balance.'], 422);
        }

        WinhouseTransaction::create([
            'provider_transaction_id' => $transactionId,
            'action' => $action,
            'user_id' => $user->id,
            'ticket_id' => $payload['ticket_id'] ?? null,
            'provider_session_id' => $session->external_session_id,
            'amount' => $amount,
            'currency' => $currency,
            'response_balance' => $user->credits,
            'payload_hash' => $payloadHash,
            'metadata' => [
                'callback' => $payload,
                'withdrawable_debit' => $move->withdrawableDebit,
                'bonus_kept' => $move->bonusKept,
            ],
        ]);

        $session->update(['last_activity_at' => now()]);

        return response()->json(['status' => 'ok', 'balance' => $this->formatAmount($user->credits)]);
    }

    /* ------------------------------------------------------------------ */

    private function callbackTokenIsValid(Request $request): bool
    {
        $expected = Winhouse::callbackToken();
        if ($expected === '') {
            // Fail closed. An unset token must never mean "allow everyone".
            return false;
        }
        $provided = trim((string) ($request->query('token') ?? $request->header('X-Callback-Token')));

        return $provided !== '' && strlen($provided) === strlen($expected) && hash_equals($expected, $provided);
    }

    private function signatureIsValid(array $payload): bool
    {
        $secret = Winhouse::apiKey();
        $provided = strtolower(trim((string) ($payload['signature'] ?? '')));
        if ($secret === '' || strlen($provided) !== 64) {
            return false;
        }

        // Five fields, no separator, in this order; amount as it read on the
        // wire (WinHouse docs §4.3). json_decode has already turned `-25` into a
        // float, so it is put back the way JavaScript would print it.
        $base = (string) ($payload['username'] ?? '')
            .$this->wireString($payload['amount'] ?? '')
            .(string) ($payload['action'] ?? '')
            .(string) ($payload['ticket_id'] ?? '')
            .(string) ($payload['transaction_id'] ?? '');

        return hash_equals(hash_hmac('sha256', $base, $secret), $provided);
    }

    /**
     * A JSON number the way its sender printed it: WinHouse sign the amount
     * as `-25` or `-25.5`, never `-25.00`, and PHP's (string) of a float can
     * differ from that (it rounds to 14 digits). Integral floats print without
     * a fraction; others in the shortest round-trip form, which is what
     * JavaScript's String() gives.
     */
    private function wireString(mixed $value): string
    {
        if (is_float($value)) {
            if (floor($value) === $value && abs($value) < 1e15) {
                return (string) (int) $value;
            }

            return rtrim(rtrim(sprintf('%.17g', $value), '0'), '.') === (string) $value
                ? (string) $value
                : json_encode($value);
        }

        return (string) $value;
    }

    private function user(string $token): ?User
    {
        $id = (int) preg_replace('/^player_/', '', trim($token));

        return $id > 0 ? User::query()->find($id) : null;
    }

    private function action(array $payload, float $delta): string
    {
        $declared = strtolower(trim((string) ($payload['action'] ?? '')));
        if (in_array($declared, ['bet', 'win', 'void'], true)) {
            return $declared;
        }

        return $delta < 0 ? 'bet' : 'win';
    }

    private function validSession(User $user, string $externalId): ?WinhouseSession
    {
        $query = WinhouseSession::query()->where('user_id', $user->id)->where('status', 'active');
        if ($externalId !== '') {
            $query->where('external_session_id', $externalId);
        }
        $session = $query->latest('id')->first();

        return $session && $session->isUsable() ? $session : null;
    }

    /** Round to cents once, here, so no other path can introduce drift. */
    private function decimalDelta(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('A numeric amount is required.');
        }

        return round((float) $value, 2);
    }

    private function formatAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** Order-independent hash of the payload, minus the signature itself. */
    private function payloadHash(array $payload): string
    {
        unset($payload['signature']);
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                ksort($value);

                return array_map($normalize, $value);
            }

            return is_float($value) ? number_format($value, 2, '.', '') : $value;
        };

        return hash('sha256', json_encode($normalize($payload)));
    }
}
