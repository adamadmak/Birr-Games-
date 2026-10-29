<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentOrder;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Validation\ValidationException;

final class FinancialRuleService
{
    public function assertDepositAllowed(User $user, float $amount): void
    {
        $this->assertRange($amount, 'amount', 'deposit', $this->number('min_deposit_amount', 50), $this->number('max_deposit_amount', 100_000));

        if ($user->deposit_cap !== null && $amount > (float) $user->deposit_cap) {
            $this->fail('amount', 'This deposit exceeds your account cap of '.number_format((float) $user->deposit_cap, 2).' ETB.');
        }

        $this->assertDailyUserLimit($user, 'deposit', $amount, 'daily_max_deposit');
    }

    public function assertWithdrawalAllowed(User $user, float $amount): void
    {
        $minimum = $this->number('min_withdraw_amount', 30);
        $maximum = $this->number('max_withdraw_amount', 30000);

        $this->assertRange($amount, 'amount', 'withdrawal', $minimum, $maximum);

        if ($user->withdrawal_cap !== null && $amount > (float) $user->withdrawal_cap) {
            $this->fail('amount', 'This request exceeds your withdrawal cap of '.number_format((float) $user->withdrawal_cap, 2).' ETB.');
        }
        if ($amount > (float) $user->withdrawable_balance || $amount > (float) $user->credits) {
            $this->fail('amount', 'The amount exceeds your available withdrawable balance.');
        }

        $this->assertDailyUserLimit($user, 'withdrawal', $amount, 'daily_max_withdraw');
    }

    public function assertAgentDirectDepositAllowed(User $user, float $amount): void
    {
        $this->assertDepositAllowed($user, $amount);
        $this->assertAgentOrderRange($amount);
    }

    public function assertAgentTransferAllowed(Agent $agent, float $amount): void
    {
        $minimum = $this->number('min_transfer_amount', 0);
        $maximum = $this->number('max_transfer_amount', 0);
        $this->assertRange($amount, 'amount', 'transfer', $minimum, $maximum);

        $dailyMaximum = $this->number('daily_max_transfer', 0);
        if ($dailyMaximum <= 0) {
            return;
        }

        $used = abs((float) WalletTransaction::query()
            ->where('agent_id', $agent->id)
            ->where('type', 'agent_transfer')
            ->where('status', 'success')
            ->where('amount', '<', 0)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('amount'));

        if ($used + $amount > $dailyMaximum) {
            $this->fail('amount', 'This transfer exceeds the remaining daily limit of '.number_format(max(0, $dailyMaximum - $used), 2).' ETB.');
        }
    }

    public function assertAgentOrderRange(float $amount): void
    {
        $this->assertRange(
            $amount,
            'amount',
            'agent order',
            $this->number('min_agent_order', 0),
            $this->number('max_agent_order', 0)
        );
    }

    /** Order value that earns one commission payment. */
    public function agentCommissionThreshold(): float
    {
        return max(0, $this->number('agent_commission_threshold', 10_000));
    }

    /** Flat amount paid for a qualifying order. */
    public function agentCommissionAmount(): float
    {
        return max(0, $this->number('agent_commission_amount', 200));
    }

    /** True when an order pays once per whole block of the threshold instead of once in total. */
    public function agentCommissionPaysPerBlock(): bool
    {
        return $this->agentCommissionMode() === 'per_block';
    }

    /** per_order and per_block pay the flat amount; percent pays a share of every order. */
    public function agentCommissionMode(): string
    {
        $mode = (string) PlatformSetting::valueFor('agent_commission_mode', 'per_order');

        return in_array($mode, ['per_order', 'per_block', 'percent'], true) ? $mode : 'per_order';
    }

    /** The administrator's percentage for this kind of order ('deposit' or 'withdrawal'), 0 to 100. */
    public function agentCommissionPercent(string $kind): float
    {
        $key = $kind === 'withdrawal' ? 'agent_commission_withdrawal_percent' : 'agent_commission_deposit_percent';

        return min(100, max(0, $this->number($key, 0)));
    }

    /**
     * What an agent earns for completing an order, paid on top of the float.
     *
     * Flat (per_order, the default): 200 ETB on an order that reaches 10,000 ETB.
     * 9,999 pays nothing, 10,000 pays 200, 25,000 pays 200 — or 400 in per_block,
     * one payment for each whole 10,000.
     *
     * Percent: the administrator's share of every order, set separately for
     * deposits and withdrawals; the threshold does not apply. 1.5% of a
     * 4,000 ETB deposit pays 60.
     *
     * @param  string  $kind  'deposit' or 'withdrawal'
     */
    public function agentCommissionFor(Agent $agent, float $amount, string $kind = 'deposit'): float
    {
        if (! $agent->commission_enabled || $amount <= 0) {
            return 0.0;
        }

        if ($this->agentCommissionMode() === 'percent') {
            return round($amount * $this->agentCommissionPercent($kind) / 100, 2);
        }

        $threshold = $this->agentCommissionThreshold();
        $reward = $this->agentCommissionAmount();

        if ($threshold <= 0 || $reward <= 0 || $amount < $threshold) {
            return 0.0;
        }

        $blocks = $this->agentCommissionPaysPerBlock() ? floor($amount / $threshold) : 1;

        return round($reward * $blocks, 2);
    }

    /** One-line description of the live rule, for the admin and agent screens. */
    public function agentCommissionSummary(): string
    {
        if ($this->agentCommissionMode() === 'percent') {
            $deposit = $this->agentCommissionPercent('deposit');
            $withdrawal = $this->agentCommissionPercent('withdrawal');

            return $deposit <= 0 && $withdrawal <= 0
                ? 'No commission is paid.'
                : rtrim(rtrim(number_format($deposit, 2), '0'), '.').'% of each deposit and '
                    .rtrim(rtrim(number_format($withdrawal, 2), '0'), '.').'% of each withdrawal';
        }

        $threshold = $this->agentCommissionThreshold();
        $reward = $this->agentCommissionAmount();

        if ($threshold <= 0 || $reward <= 0) {
            return 'No commission is paid.';
        }

        return number_format($reward, 2).' ETB on deposits and withdrawals of '
            .number_format($threshold, 2).' ETB or more'
            .($this->agentCommissionPaysPerBlock() ? ', for each whole block' : ', once per order');
    }

    public function minimumWithdrawal(): float
    {
        return max(0, $this->number('min_withdraw_amount', 30));
    }

    /**
     * The band a player may actually request, for the form to draw and explain.
     *
     * Four numbers narrow a withdrawal — the platform minimum and maximum, the
     * player's own cap, and the part of their balance that is withdrawable —
     * and the form has to read them exactly as assertWithdrawalAllowed() does,
     * or the player is stopped by whichever is strictest with nothing to say
     * which one it was.
     *
     * It did not. A maximum of zero switches that ceiling OFF in assertRange()
     * below, while the form took zero as a ceiling of zero and then clamped it
     * back up to the minimum so that `min` and `max` were both 30 — a field
     * accepting exactly one value. A player holding 23,000 withdrawable was
     * told "Value must be 30." by the browser: the platform minimum, quoted as
     * though it were the only legal amount.
     *
     * `binding` names whichever limit is doing the narrowing so the page can
     * say why, instead of quoting a number nothing on it explains.
     *
     * @return array{minimum: float, maximum: float, binding: string, open: bool, heading: string, note: string}
     */
    public function withdrawalRange(User $user): array
    {
        $minimum = $this->minimumWithdrawal();

        // Zero means "no limit" for the platform maximum, exactly as
        // assertRange() reads it: clearing that box switches the ceiling off,
        // it does not forbid every withdrawal. The balance and the player's own
        // cap are real numbers and zero means zero for both.
        // Both, because assertWithdrawalAllowed() refuses on either: the wallet
        // total should never sit below the withdrawable part, and if it does the
        // form has to be stopped by the same number the server stops on.
        $limits = ['balance' => min((float) $user->withdrawable_balance, (float) $user->credits)];

        if (($platform = $this->number('max_withdraw_amount', 30000)) > 0) {
            $limits['platform'] = $platform;
        }

        if ($user->withdrawal_cap !== null) {
            $limits['cap'] = (float) $user->withdrawal_cap;
        }

        $maximum = min($limits);
        $binding = (string) array_search($maximum, $limits, true);
        $open = $maximum > 0 && $maximum >= $minimum;

        return [
            'minimum' => $minimum,
            'maximum' => $maximum,
            'binding' => $binding,
            'open' => $open,
            'heading' => $open ? '' : ($binding === 'balance' ? 'No withdrawable balance' : 'Withdrawals unavailable'),
            'note' => $open ? '' : match ($binding) {
                'cap' => 'Your account has a withdrawal cap of '.number_format($maximum, 2).' ETB, which is below the '
                    .number_format($minimum, 2).' ETB minimum, so no amount can be requested. An administrator can raise it.',
                'platform' => 'The maximum withdrawal is set to '.number_format($maximum, 2).' ETB, below the '
                    .number_format($minimum, 2).' ETB minimum, so no amount can be requested. An administrator can correct it.',
                default => 'You have '.number_format($maximum, 2).' ETB withdrawable and the minimum withdrawal is '
                    .number_format($minimum, 2).' ETB. Bonus or locked funds cannot be requested.',
            },
        ];
    }

    private function assertDailyUserLimit(User $user, string $type, float $amount, string $setting): void
    {
        $dailyMaximum = $this->number($setting, 0);
        if ($dailyMaximum <= 0) {
            return;
        }

        $used = (float) AgentOrder::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereIn('status', ['pending', 'success'])
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('amount');

        if ($used + $amount > $dailyMaximum) {
            $this->fail('amount', 'This request exceeds the remaining daily '.$type.' limit of '.number_format(max(0, $dailyMaximum - $used), 2).' ETB.');
        }
    }

    private function assertRange(float $amount, string $field, string $label, float $minimum, float $maximum): void
    {
        if ($minimum > 0 && $amount < $minimum) {
            $this->fail($field, 'The minimum '.$label.' is '.number_format($minimum, 2).' ETB.');
        }
        if ($maximum > 0 && $amount > $maximum) {
            $this->fail($field, 'The maximum '.$label.' is '.number_format($maximum, 2).' ETB.');
        }
    }

    /**
     * The least an agent's float may hold after funding a deposit, sending
     * credit or any other debit. An agent registered with 10,000 ETB used to be
     * able to approve deposits right down to zero, and then could not serve
     * anyone until the administrator noticed. Below this the debit is refused
     * and the agent is told to top up. 0 switches the floor off.
     */
    public function agentMinimumFloat(): float
    {
        return max(0.0, $this->number('agent_min_float', 1000));
    }

    /** At or below this the agent is warned (portal banner, a Telegram notice). Never below the minimum. */
    public function agentLowFloatWarning(): float
    {
        return max($this->agentMinimumFloat(), $this->number('agent_low_float_warning', 2500));
    }

    /**
     * Why an agent cannot pay this much out of their float, or null when they can.
     * Used by every path that debits an agent: deposit approval (agent and
     * admin), direct deposit, and credit sent to another agent.
     */
    public function agentFloatRefusal(\App\Models\Agent $agent, float $amount): ?string
    {
        $balance = (float) $agent->balance;
        $minimum = $this->agentMinimumFloat();

        if ($balance < $amount) {
            return 'The agent float of '.number_format($balance, 2).' ETB does not cover '.number_format($amount, 2).' ETB.';
        }
        if ($minimum > 0 && $balance - $amount < $minimum) {
            return 'This would leave the agent float at '.number_format($balance - $amount, 2).' ETB, below the minimum of '
                .number_format($minimum, 2).' ETB. Ask the administrator to top up the float first.';
        }

        return null;
    }

    /** Whether a debit just took the float from above the warning level to at or below it. */
    public function agentFloatJustRanLow(float $before, float $after): bool
    {
        $warning = $this->agentLowFloatWarning();

        return $warning > 0 && $before > $warning && $after <= $warning;
    }

    /**
     * What a player is told a deposit usually takes an agent to confirm, in
     * minutes. A stated time is a promise the operator sets on Configuration →
     * System, not a guess the page makes.
     */
    public function depositReviewMinutes(): int
    {
        return max(1, (int) PlatformSetting::valueFor('deposit_review_minutes', 15));
    }

    /** What a player is told a withdrawal usually takes an agent to pay, in hours. */
    public function withdrawalReviewHours(): int
    {
        return max(1, (int) PlatformSetting::valueFor('withdrawal_review_hours', 24));
    }

    private function number(string $key, float $default): float
    {
        return (float) PlatformSetting::valueFor($key, $default);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
