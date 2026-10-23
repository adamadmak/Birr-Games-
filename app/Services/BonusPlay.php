<?php

namespace App\Services;

use App\Models\BonusStake;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps bonus credit from turning into withdrawable cash by being played.
 *
 * A player's wallet has two parts: `withdrawable_balance`, which they can cash
 * out, and the rest of `credits`, which is play-only (bonus, welcome balance,
 * administrator-locked credit). A stake is paid from the play-only part first.
 *
 * Before this class, every payout was credited to the withdrawable part in
 * full. That meant a stake paid with bonus came back as cash the first time
 * the round paid anything: a 1.01x win on a 100 ETB bonus bet produced 101
 * withdrawable. The bonus was only ever "locked" until it was bet once.
 *
 * Now a round remembers how much of its stake was bonus. When it pays out:
 *
 *   - if the round paid at least the stake, the bonus part of the stake goes
 *     back to the play-only wallet and everything above it (the winnings, and
 *     the cash part of the stake) is withdrawable;
 *   - if it paid less, the payout is split in proportion to what was staked,
 *     so a partly lost round loses bonus and cash alike.
 *
 * The bonus can be played for as long as it lasts, the player keeps what it
 * wins, and the bonus itself can never be cashed.
 *
 * Every caller already holds the player's row lock, so nothing here locks the
 * user. A failure must never cost a player their bet or their win, so each
 * public method swallows its own errors and falls back to the old behaviour
 * (payout fully withdrawable) rather than failing the wallet callback. The work
 * runs in a savepoint so a failed statement cannot poison the caller's
 * transaction on a database that aborts the whole transaction after one error.
 */
final class BonusPlay
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('bonus_stakes');
            } catch (\Throwable) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    /** Forget the cached table check; the migration runner and tests call this. */
    public static function reset(): void
    {
        self::$ready = null;
    }

    /**
     * The part of a payout that stays play-only, given the whole round's totals.
     * Pure arithmetic, so the rule can be read and tested in one place.
     */
    public static function lockedShare(float $stake, float $bonusStaked, float $paidTotal): float
    {
        if ($stake <= 0 || $bonusStaked <= 0 || $paidTotal <= 0) {
            return 0.0;
        }

        $bonusStaked = min($bonusStaked, $stake);

        return $paidTotal >= $stake
            ? round($bonusStaked, 2)
            : round($paidTotal * $bonusStaked / $stake, 2);
    }

    /**
     * Record a stake. `$withdrawableDebit` is how much of it came out of the
     * withdrawable balance; the rest of the stake was bonus credit.
     */
    public static function stake(User $user, string $source, string $roundRef, float $stake, float $withdrawableDebit): void
    {
        if ($stake <= 0 || $roundRef === '' || ! self::ready()) {
            return;
        }

        self::guarded('stake', null, function () use ($user, $source, $roundRef, $stake, $withdrawableDebit): void {
            $bonus = max(0.0, round($stake - $withdrawableDebit, 2));
            $row = self::row($user, $source, $roundRef);

            // An all-cash stake needs no row unless the round already has one,
            // where the total must still grow so a later payout is measured
            // against everything that was staked.
            if (! $row && $bonus <= 0) {
                return;
            }

            if (! $row) {
                BonusStake::create([
                    'user_id' => $user->id, 'source' => $source, 'round_ref' => $roundRef,
                    'stake_total' => $stake, 'bonus_staked' => $bonus,
                ]);

                return;
            }

            $row->forceFill([
                'stake_total' => round((float) $row->stake_total + $stake, 2),
                'bonus_staked' => round((float) $row->bonus_staked + $bonus, 2),
            ])->save();
        });
    }

    /** Take a stake back (a provider rollback). Mirrors stake(). */
    public static function unstake(User $user, string $source, string $roundRef, float $stake, float $withdrawableDebit): void
    {
        if ($stake <= 0 || $roundRef === '' || ! self::ready()) {
            return;
        }

        self::guarded('unstake', null, function () use ($user, $source, $roundRef, $stake, $withdrawableDebit): void {
            $row = self::row($user, $source, $roundRef);
            if (! $row) {
                return;
            }

            $bonus = max(0.0, round($stake - $withdrawableDebit, 2));
            $row->forceFill([
                'stake_total' => max(0, round((float) $row->stake_total - $stake, 2)),
                'bonus_staked' => max(0, round((float) $row->bonus_staked - $bonus, 2)),
            ])->save();

            if ((float) $row->stake_total <= 0) {
                $row->delete();
            }
        });
    }

    /**
     * A payout (or a void, which is a payout of the stake). Returns how much of
     * it must stay play-only; the caller credits the rest as withdrawable.
     */
    public static function payout(User $user, string $source, string $roundRef, float $payout): float
    {
        if ($payout <= 0 || $roundRef === '' || ! self::ready()) {
            return 0.0;
        }

        return (float) self::guarded('payout', 0.0, function () use ($user, $source, $roundRef, $payout): float {
            $row = self::row($user, $source, $roundRef);
            if (! $row) {
                return 0.0;
            }

            $paid = round((float) $row->paid_total + $payout, 2);
            $target = self::lockedShare((float) $row->stake_total, (float) $row->bonus_staked, $paid);
            $locked = min($payout, max(0.0, round($target - (float) $row->bonus_returned, 2)));

            $row->forceFill([
                'paid_total' => $paid,
                'bonus_returned' => round((float) $row->bonus_returned + $locked, 2),
            ])->save();

            return $locked;
        });
    }

    /** A round that is staked and paid in one step (the built-in games). */
    public static function immediate(float $stake, float $withdrawableDebit, float $payout): float
    {
        $bonus = max(0.0, round($stake - $withdrawableDebit, 2));

        return min($payout, self::lockedShare($stake, $bonus, $payout));
    }

    /** Settled rounds are pointless to keep; called from the scheduler. */
    public static function prune(int $days = 45): int
    {
        if (! self::ready()) {
            return 0;
        }

        return BonusStake::query()->where('updated_at', '<', now()->subDays($days))->delete();
    }

    private static function row(User $user, string $source, string $roundRef): ?BonusStake
    {
        return BonusStake::query()
            ->where('user_id', $user->id)->where('source', $source)->where('round_ref', $roundRef)
            ->lockForUpdate()->first();
    }

    private static function guarded(string $what, mixed $fallback, \Closure $work): mixed
    {
        try {
            return DB::transaction($work);
        } catch (\Throwable $exception) {
            report($exception);
            Log::warning('Bonus play bookkeeping failed; the wallet movement carried on without it.', [
                'step' => $what,
                'error' => $exception->getMessage(),
            ]);

            return $fallback;
        }
    }
}
