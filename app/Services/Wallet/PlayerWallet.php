<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\BonusPlay;
use App\Services\WageringService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one place a player's wallet moves for play.
 *
 * Until 28 Oct 2026 the stake split, the payout rule, the ledger row and the
 * wagering credit were copied into each provider callback (QTech, BigBang,
 * WinHouse) and the built-in games, and had drifted: one path rounded, one
 * did not, one truncated a payout to a whole Birr on one side of the wallet
 * but not the other. Every path now calls this and keeps only what is its
 * own — the provider's protocol, its idempotency row and its round mirror.
 *
 * The rules, in one place:
 *
 *  - A wallet has two buckets (CLAUDE.md): `credits` is the whole wallet,
 *    `withdrawable_balance` the part a player can cash out. A stake is paid
 *    from play-only credit first, so placing a bet never converts bonus into
 *    cash. A payout raises the withdrawable balance by everything except the
 *    bonus part of the stake coming back (BonusPlay remembers how much that
 *    was). A refund puts the money back the way it came out. A reward is cash.
 *  - Every movement happens inside the caller's transaction with the
 *    player's row locked: two settlements racing for the last of a balance
 *    must not both read it as available. lock() takes the lock; every mover
 *    refuses to run outside a transaction.
 *  - Every amount is rounded to cents once, here, so no path drifts.
 *  - Every real movement writes one ledger row, and a real stake counts
 *    toward wagering. A zero amount (QTech's free-round marker) moves nothing
 *    and writes nothing.
 */
final class PlayerWallet
{
    public function __construct(private readonly WageringService $wagering)
    {
    }

    /** The player's row, locked until the caller's transaction ends. */
    public function lock(User|int $user): User
    {
        $this->requireTransaction();

        return User::query()->lockForUpdate()->findOrFail($user instanceof User ? $user->id : $user);
    }

    /** Whether the whole wallet covers a stake. */
    public function covers(User $user, float $amount): bool
    {
        return (float) $user->credits >= self::cents($amount);
    }

    /**
     * Take a stake, play-only credit first.
     *
     * @param  string  $source  the BonusPlay source: 'winhouse', 'bigbang', 'qtech'
     * @param  string  $roundRef  the round the stake belongs to, for its payout to be measured against; '' when unknown
     *
     * @throws InsufficientFunds when the wallet does not cover it; nothing has moved
     */
    public function stake(User $user, float $amount, string $source, string $roundRef, LedgerEntry $ledger): Movement
    {
        $this->requireTransaction();
        $amount = self::cents($amount);

        if ($amount <= 0) {
            return $this->nothing($user);
        }

        $credits = (float) $user->credits;
        if ($credits < $amount) {
            throw new InsufficientFunds($amount, $credits);
        }

        $withdrawable = (float) $user->withdrawable_balance;
        $lockedCredits = max(0.0, self::cents($credits - $withdrawable));
        $withdrawableDebit = max(0.0, self::cents($amount - $lockedCredits));

        $user->credits = self::cents($credits - $amount);
        $user->withdrawable_balance = max(0.0, self::cents($withdrawable - $withdrawableDebit));
        $user->save();

        BonusPlay::stake($user, $source, $roundRef, $amount, $withdrawableDebit);
        $row = $this->ledger($user, 'bet', -$amount, $ledger);
        $this->wagering->recordStake($user, $amount);

        return $this->movement($user, $amount, $withdrawableDebit, 0.0, $row);
    }

    /**
     * Pay a win, or a void (which pays the stake back). Everything becomes
     * withdrawable except the bonus part of the round's stake coming home.
     */
    public function payout(User $user, float $amount, string $source, string $roundRef, LedgerEntry $ledger): Movement
    {
        $this->requireTransaction();
        $amount = self::cents($amount);

        if ($amount <= 0) {
            return $this->nothing($user);
        }

        $bonusKept = self::cents(BonusPlay::payout($user, $source, $roundRef, $amount));

        $user->credits = self::cents((float) $user->credits + $amount);
        $user->withdrawable_balance = self::cents((float) $user->withdrawable_balance + $amount - $bonusKept);
        $user->save();

        $row = $this->ledger($user, 'payout', $amount, $ledger);

        return $this->movement($user, $amount, 0.0, $bonusKept, $row);
    }

    /**
     * Give a stake back (a provider rollback), the way it came out: the cash
     * part to the withdrawable balance, the rest as play-only credit.
     *
     * @param  float  $withdrawableDebit  what the original stake took from the withdrawable balance
     */
    public function refund(User $user, float $amount, float $withdrawableDebit, string $source, string $roundRef, LedgerEntry $ledger): Movement
    {
        $this->requireTransaction();
        $amount = self::cents($amount);

        if ($amount <= 0) {
            return $this->nothing($user);
        }

        $withdrawableDebit = min($amount, max(0.0, self::cents($withdrawableDebit)));

        $user->credits = self::cents((float) $user->credits + $amount);
        $user->withdrawable_balance = self::cents((float) $user->withdrawable_balance + $withdrawableDebit);
        $user->save();

        BonusPlay::unstake($user, $source, $roundRef, $amount, $withdrawableDebit);
        $row = $this->ledger($user, 'refund', $amount, $ledger);

        return $this->movement($user, $amount, $withdrawableDebit, 0.0, $row);
    }

    /** A prize outside any round (a tournament, a campaign): real money, all of it. */
    public function reward(User $user, float $amount, LedgerEntry $ledger): Movement
    {
        $this->requireTransaction();
        $amount = self::cents($amount);

        if ($amount <= 0) {
            return $this->nothing($user);
        }

        $user->credits = self::cents((float) $user->credits + $amount);
        $user->withdrawable_balance = self::cents((float) $user->withdrawable_balance + $amount);
        $user->save();

        $row = $this->ledger($user, 'payout', $amount, $ledger);

        return $this->movement($user, $amount, 0.0, 0.0, $row);
    }

    /**
     * A round staked and settled in one step (the built-in games). The same
     * split as stake() followed by payout(), without a BonusPlay row because
     * nothing is left open.
     *
     * @throws InsufficientFunds when the wallet does not cover the stake; nothing has moved
     */
    public function playRound(User $user, float $stake, float $payout, LedgerEntry $stakeEntry, LedgerEntry $payoutEntry): Movement
    {
        $this->requireTransaction();
        $stake = self::cents($stake);
        $payout = max(0.0, self::cents($payout));

        $credits = (float) $user->credits;
        if ($stake <= 0 || $credits < $stake) {
            throw new InsufficientFunds($stake, $credits);
        }

        $withdrawable = (float) $user->withdrawable_balance;
        $lockedCredits = max(0.0, self::cents($credits - $withdrawable));
        $withdrawableDebit = max(0.0, self::cents($stake - $lockedCredits));
        $bonusKept = self::cents(BonusPlay::immediate($stake, $withdrawableDebit, $payout));

        $user->credits = self::cents($credits - $stake + $payout);
        $user->withdrawable_balance = max(0.0, self::cents($withdrawable - $withdrawableDebit + $payout - $bonusKept));
        $user->save();

        $row = $this->ledger($user, 'bet', -$stake, $stakeEntry);
        if ($payout > 0) {
            $this->ledger($user, 'payout', $payout, $payoutEntry);
        }
        $this->wagering->recordStake($user, $stake);

        return $this->movement($user, $stake, $withdrawableDebit, $bonusKept, $row);
    }

    /** Round to cents once, here, so no other path can introduce drift. */
    public static function cents(float $amount): float
    {
        return round($amount, 2);
    }

    /* ------------------------------------------------------------------ */

    private function ledger(User $user, string $type, float $signedAmount, LedgerEntry $entry): WalletTransaction
    {
        return WalletTransaction::create([
            'ledger_key' => $entry->key,
            'user_id' => $user->id,
            'type' => $type,
            'amount' => $signedAmount,
            'status' => 'success',
            'method' => $entry->method,
            'reason' => $entry->reason,
            'currency' => 'ETB',
            'source_type' => $entry->sourceType,
            'source_id' => $entry->sourceId,
            'metadata' => $entry->metadata !== [] ? $entry->metadata : null,
        ]);
    }

    private function movement(User $user, float $amount, float $withdrawableDebit, float $bonusKept, ?WalletTransaction $row): Movement
    {
        return new Movement(
            amount: $amount,
            withdrawableDebit: $withdrawableDebit,
            bonusKept: $bonusKept,
            credits: (float) $user->credits,
            withdrawable: (float) $user->withdrawable_balance,
            ledger: $row,
        );
    }

    private function nothing(User $user): Movement
    {
        return $this->movement($user, 0.0, 0.0, 0.0, null);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A wallet movement needs the caller\'s transaction and the player\'s row lock (PlayerWallet::lock).');
        }
    }
}
