<?php

namespace App\Services;

use App\Models\AgentOrder;
use App\Models\DepositBonusGrant;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The deposit bonus: an administrator switches it on, picks a percentage
 * (100% matches the deposit, 50% pays half of it, and so on), and every
 * qualifying approved deposit earns that much extra play credit.
 *
 * The rule that matters: bonus credit is NEVER withdrawable.
 *
 *   - It is added to `users.credits` only. `withdrawable_balance` is untouched,
 *     and a withdrawal is limited to `withdrawable_balance`.
 *   - It can be staked. Winnings from it are withdrawable, but the bonus part
 *     of a stake always returns to the play-only wallet (App\Services\BonusPlay),
 *     so playing it never converts it to cash.
 *
 * Safeguards, each one a setting an administrator can see:
 *   - a cap on a single bonus and on a player's lifetime bonus total;
 *   - a minimum qualifying deposit;
 *   - first deposit only, or every deposit;
 *   - a daily budget for all bonuses given, which stops a runaway campaign;
 *   - an expiry after which unused bonus is removed;
 *   - a withdrawal cancels any bonus the player has not used, so a player
 *     cannot take their own money out and keep playing on the house's;
 *   - one bonus per deposit order, enforced by the database, so a replayed or
 *     double-clicked approval pays once.
 *
 * Every public method is safe to call from a money flow: if the tables are not
 * there yet (this host does not run migrations on deploy), the bonus is simply
 * not paid and the deposit itself goes through untouched.
 */
class DepositBonusService
{
    public const LEDGER_PREFIX = 'deposit-bonus-order-';

    private static ?bool $ready = null;

    public static function tablesReady(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('deposit_bonus_grants');
            } catch (\Throwable) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    public static function resetTableCheck(): void
    {
        self::$ready = null;
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    /** @return array{enabled: bool, percent: float, max_amount: float, min_deposit: float, scope: string, lifetime_cap: float, valid_days: int, daily_budget: float, forfeit_on_withdrawal: bool} */
    public function settings(): array
    {
        $scope = (string) PlatformSetting::valueFor('deposit_bonus_scope', 'first');

        return [
            'enabled' => (bool) (int) PlatformSetting::valueFor('deposit_bonus_enabled', 0),
            'percent' => max(0.0, min(100.0, (float) PlatformSetting::valueFor('deposit_bonus_percent', 100))),
            'max_amount' => max(0.0, (float) PlatformSetting::valueFor('deposit_bonus_max_amount', 5000)),
            'min_deposit' => max(0.0, (float) PlatformSetting::valueFor('deposit_bonus_min_deposit', 100)),
            'scope' => $scope === 'every' ? 'every' : 'first',
            'lifetime_cap' => max(0.0, (float) PlatformSetting::valueFor('deposit_bonus_lifetime_cap', 0)),
            'valid_days' => max(0, (int) PlatformSetting::valueFor('deposit_bonus_valid_days', 30)),
            'daily_budget' => max(0.0, (float) PlatformSetting::valueFor('deposit_bonus_daily_budget', 0)),
            'forfeit_on_withdrawal' => (bool) (int) PlatformSetting::valueFor('deposit_bonus_forfeit_on_withdrawal', 1),
        ];
    }

    public function enabled(): bool
    {
        return self::tablesReady() && $this->settings()['enabled'] && $this->settings()['percent'] > 0;
    }

    /* ------------------------------------------------------------------ */
    /* Offer                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * What a deposit of `$amount` would earn this player right now, or 0 when
     * nothing. Used to tell the player before they pay, and by the grant itself,
     * so the number promised is the number paid.
     */
    public function quote(User $user, float $amount, ?int $exceptOrderId = null): float
    {
        if (! $this->enabled() || $user->role !== User::ROLE_PLAYER || ! $user->active) {
            return 0.0;
        }

        $settings = $this->settings();
        if ($amount <= 0 || $amount < $settings['min_deposit']) {
            return 0.0;
        }

        if ($settings['scope'] === 'first' && $this->hasEarlierDeposit($user, $exceptOrderId)) {
            return 0.0;
        }

        $bonus = round($amount * $settings['percent'] / 100, 2);
        if ($settings['max_amount'] > 0) {
            $bonus = min($bonus, $settings['max_amount']);
        }

        if ($settings['lifetime_cap'] > 0) {
            $given = (float) DepositBonusGrant::query()->where('user_id', $user->id)->sum('amount');
            $bonus = min($bonus, max(0.0, $settings['lifetime_cap'] - $given));
        }

        if ($settings['daily_budget'] > 0) {
            $today = (float) DepositBonusGrant::query()->where('created_at', '>=', now()->startOfDay())->sum('amount');
            if ($today + $bonus > $settings['daily_budget']) {
                return 0.0;
            }
        }

        return $bonus >= 0.01 ? round($bonus, 2) : 0.0;
    }

    /** A short line for the deposit screen, or null when there is no offer for this player. */
    public function offerFor(User $user): ?array
    {
        if (! $this->enabled() || $user->role !== User::ROLE_PLAYER) {
            return null;
        }

        $settings = $this->settings();
        if ($settings['scope'] === 'first' && $this->hasEarlierDeposit($user, null)) {
            return null;
        }
        if ($settings['lifetime_cap'] > 0
            && (float) DepositBonusGrant::query()->where('user_id', $user->id)->sum('amount') >= $settings['lifetime_cap']) {
            return null;
        }

        return [
            'percent' => $settings['percent'],
            'max_amount' => $settings['max_amount'],
            'min_deposit' => $settings['min_deposit'],
            'scope' => $settings['scope'],
            'valid_days' => $settings['valid_days'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Grant                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Pay the bonus for a deposit that was just approved. Call inside the same
     * transaction that credited the deposit, with the player's row locked.
     * Never throws: a failure here is reported and the deposit stands.
     */
    public function grantForDeposit(User $user, AgentOrder $order, float $amount): ?DepositBonusGrant
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($user, $order, $amount): ?DepositBonusGrant {
                // Idempotent: the unique key on the order is the real guard, this
                // just avoids the exception on an ordinary replay.
                if (DepositBonusGrant::query()->where('agent_order_id', $order->id)->exists()) {
                    return null;
                }

                $bonus = $this->quote($user, $amount, $order->id);
                if ($bonus <= 0) {
                    return null;
                }

                $settings = $this->settings();
                $grant = DepositBonusGrant::create([
                    'user_id' => $user->id,
                    'agent_order_id' => $order->id,
                    'deposit_amount' => $amount,
                    'percent' => $settings['percent'],
                    'amount' => $bonus,
                    'status' => DepositBonusGrant::STATUS_ACTIVE,
                    'expires_at' => $settings['valid_days'] > 0 ? now()->addDays($settings['valid_days']) : null,
                ]);

                // credits only: withdrawable_balance is deliberately not touched.
                $user->increment('credits', $bonus);

                WalletTransaction::create([
                    'ledger_key' => self::LEDGER_PREFIX.$order->id,
                    'user_id' => $user->id,
                    'type' => 'bonus',
                    'amount' => $bonus,
                    'status' => 'success',
                    'method' => 'deposit_bonus',
                    'reason' => rtrim(rtrim(number_format($settings['percent'], 2), '0'), '.').'% deposit bonus (play-only, not withdrawable)',
                    'currency' => 'ETB',
                    'source_type' => 'deposit_bonus_grant',
                    'source_id' => $grant->id,
                    'metadata' => [
                        'agent_order_id' => $order->id,
                        'deposit_amount' => $amount,
                        'percent' => $settings['percent'],
                        'withdrawable' => false,
                        'expires_at' => $grant->expires_at?->toIso8601String(),
                    ],
                ]);

                return $grant;
            });
        } catch (\Throwable $exception) {
            // A dead transaction is not "the bonus could not be paid": the deposit
            // around it is gone too. See BonusPlay::propagates().
            if (BonusPlay::propagates($exception)) {
                throw $exception;
            }
            report($exception);
            Log::warning('The deposit bonus could not be paid; the deposit itself was credited.', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* What the player still has                                           */
    /* ------------------------------------------------------------------ */

    /** Play-only credit in the wallet: what is not withdrawable. */
    public function lockedCredits(User $user): float
    {
        return max(0.0, round((float) $user->credits - (float) $user->withdrawable_balance, 2));
    }

    /**
     * Deposit bonus the player still holds. The bonus is part of the play-only
     * balance, so it can never be more than that balance however much was
     * granted: what was staked and lost has already gone from it.
     */
    public function outstanding(User $user): float
    {
        if (! self::tablesReady()) {
            return 0.0;
        }

        $granted = (float) DepositBonusGrant::query()->active()->where('user_id', $user->id)
            ->selectRaw('COALESCE(SUM(amount - forfeited), 0) AS total')->value('total');

        return round(max(0.0, min($granted, $this->lockedCredits($user))), 2);
    }

    /** The earliest expiry among the player's active grants, for display. */
    public function nextExpiry(User $user): ?\Illuminate\Support\Carbon
    {
        if (! self::tablesReady()) {
            return null;
        }

        $value = DepositBonusGrant::query()->active()->where('user_id', $user->id)->whereNotNull('expires_at')->min('expires_at');

        return $value ? \Illuminate\Support\Carbon::parse($value) : null;
    }

    /* ------------------------------------------------------------------ */
    /* Taking it back                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Remove bonus credit: on expiry, on withdrawal, or by an administrator.
     * Only ever removes play-only credit, never the withdrawable balance, and
     * never more than the grants still hold. Call with the player's row locked.
     * Returns what was actually removed.
     */
    public function forfeit(User $user, float $amount, string $reason): float
    {
        if (! self::tablesReady() || $amount <= 0) {
            return 0.0;
        }

        return (float) DB::transaction(function () use ($user, $amount, $reason): float {
            $remove = min(round($amount, 2), $this->lockedCredits($user));
            if ($remove <= 0) {
                return 0.0;
            }

            $left = $remove;
            $grants = DepositBonusGrant::query()->active()->where('user_id', $user->id)->oldest('id')->lockForUpdate()->get();
            foreach ($grants as $grant) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, $grant->remaining());
                if ($take <= 0) {
                    continue;
                }
                $grant->forceFill(['forfeited' => round((float) $grant->forfeited + $take, 2)]);
                if ($grant->remaining() <= 0) {
                    $grant->status = $reason === 'expired' ? DepositBonusGrant::STATUS_EXPIRED : DepositBonusGrant::STATUS_FORFEITED;
                    $grant->forfeit_reason = $reason;
                    $grant->closed_at = now();
                }
                $grant->save();
                $left = round($left - $take, 2);
            }

            // Whatever the grants could not account for (the player already
            // spent it) is not taken: only bonus the grants still hold is removed.
            $removed = round($remove - $left, 2);
            if ($removed <= 0) {
                return 0.0;
            }

            $user->decrement('credits', $removed);

            WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'bonus_forfeit',
                'amount' => -$removed,
                'status' => 'success',
                'method' => 'deposit_bonus',
                'reason' => match ($reason) {
                    'expired' => 'Deposit bonus expired',
                    'withdrawal' => 'Deposit bonus cancelled by a withdrawal',
                    default => 'Deposit bonus removed by an administrator',
                },
                'currency' => 'ETB',
                'source_type' => 'deposit_bonus',
                'source_id' => $user->id,
                'metadata' => ['reason' => $reason, 'withdrawable' => false],
            ]);

            return $removed;
        });
    }

    /** Close grants that ran out of time. Run from the scheduler. Returns how many were closed. */
    public function expireDue(): int
    {
        if (! self::tablesReady()) {
            return 0;
        }

        $closed = 0;
        DepositBonusGrant::query()->active()->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->select('user_id')->distinct()->pluck('user_id')
            ->each(function (int $userId) use (&$closed): void {
                try {
                    DB::transaction(function () use ($userId, &$closed): void {
                        $user = User::query()->lockForUpdate()->find($userId);
                        if (! $user) {
                            return;
                        }
                        $due = DepositBonusGrant::query()->active()->where('user_id', $userId)
                            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->lockForUpdate()->get();
                        foreach ($due as $grant) {
                            $remaining = min($grant->remaining(), $this->lockedCredits($user));
                            if ($remaining > 0) {
                                $this->forfeitGrant($user, $grant, $remaining);
                            }
                            $grant->refresh();
                            if ($grant->status === DepositBonusGrant::STATUS_ACTIVE) {
                                $grant->forceFill(['status' => DepositBonusGrant::STATUS_EXPIRED, 'forfeit_reason' => 'expired', 'closed_at' => now()])->save();
                            }
                            $closed++;
                        }
                    });
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

        return $closed;
    }

    /** Remove part of ONE grant (expiry works grant by grant, so a later one is untouched). */
    private function forfeitGrant(User $user, DepositBonusGrant $grant, float $amount): void
    {
        $take = min($amount, $grant->remaining(), $this->lockedCredits($user));
        if ($take <= 0) {
            return;
        }

        $grant->forceFill(['forfeited' => round((float) $grant->forfeited + $take, 2)])->save();
        $user->decrement('credits', $take);

        WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'bonus_forfeit',
            'amount' => -$take,
            'status' => 'success',
            'method' => 'deposit_bonus',
            'reason' => 'Deposit bonus expired',
            'currency' => 'ETB',
            'source_type' => 'deposit_bonus_grant',
            'source_id' => $grant->id,
            'metadata' => ['reason' => 'expired', 'withdrawable' => false],
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function hasEarlierDeposit(User $user, ?int $exceptOrderId): bool
    {
        return WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'deposit')
            ->where('status', 'success')
            ->where('source_type', 'agent_order')
            ->when($exceptOrderId, fn ($query) => $query->where('source_id', '!=', $exceptOrderId))
            ->exists();
    }
}
