<?php

namespace App\Services;

use App\Models\BonusClaim;
use App\Models\DepositBonusConfig;
use App\Models\MemberBonus;
use App\Models\User;

class WageringService
{
    public function awardForClaim(User $user, BonusClaim $claim): ?MemberBonus
    {
        $config = DepositBonusConfig::query()
            ->available()
            ->where('bonus_type', 'initial_deposit')
            ->with('policy')
            ->latest('starts_at')
            ->first();

        if (!$config || (float) $claim->credits < (float) $config->policy->minimum_deposit) {
            return null;
        }

        $expiresAt = $this->expiryFor($config);
        $awarded = min((float) $claim->credits, (float) $config->maximum_payout ?: (float) $claim->credits);

        return MemberBonus::query()->firstOrCreate(
            ['bonus_claim_id' => $claim->id],
            [
                'user_id' => $user->id,
                'deposit_bonus_config_id' => $config->id,
                'status' => 'active',
                'amount_awarded' => $awarded,
                'rollover_required' => $awarded * (float) $config->rollover_multiplier,
                'contributed' => 0,
                'expires_at' => $expiresAt,
            ]
        );
    }

    public function recordStake(User $user, int|float $stake): void
    {
        if ($stake <= 0) {
            return;
        }

        $bonus = MemberBonus::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'active'])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('config.policy')
            ->lockForUpdate()
            ->oldest()
            ->first();

        if (!$bonus || $stake < (float) $bonus->config->policy->minimum_stake) {
            return;
        }

        $policy = $bonus->config->policy;
        $contribution = $policy->contribution_type === 'fixed_amount'
            ? (float) $policy->contribution_percentage
            : $stake * ((float) $policy->contribution_percentage / 100);
        $contribution = min($contribution, (float) $policy->maximum_contribution ?: $contribution);
        $remaining = max(0, (float) $bonus->rollover_required - (float) $bonus->contributed);
        $contribution = min($contribution, $remaining);

        if ($contribution <= 0) {
            return;
        }

        $newTotal = (float) $bonus->contributed + $contribution;
        $completed = $newTotal >= (float) $bonus->rollover_required;
        $bonus->update([
            'contributed' => $newTotal,
            'status' => $completed ? 'completed' : 'active',
            'completed_at' => $completed ? now() : null,
        ]);
    }

    public function expireOldBonuses(): void
    {
        MemberBonus::query()
            ->whereIn('status', ['pending', 'active'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    private function expiryFor(DepositBonusConfig $config): \Carbon\CarbonInterface
    {
        if (preg_match('/^(\d+)\s*(day|days|week|weeks|month|months)$/i', trim($config->validity_period), $matches)) {
            $amount = (int) $matches[1];
            return match (strtolower($matches[2])) {
                'week', 'weeks' => now()->addWeeks($amount),
                'month', 'months' => now()->addMonths($amount),
                default => now()->addDays($amount),
            };
        }

        return $config->ends_at->isFuture() ? $config->ends_at : now()->addDays(30);
    }
}
