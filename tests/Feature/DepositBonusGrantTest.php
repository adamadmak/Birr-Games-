<?php

use App\Models\AgentOrder;
use App\Models\DepositBonusGrant;
use App\Models\WalletTransaction;
use App\Services\DepositBonusService;

/*
 * The deposit bonus adds to credits only — never to withdrawable_balance —
 * and is paid once per approved deposit, whatever retries happen.
 */

beforeEach(function () {
    $this->setting('deposit_bonus_enabled', 1);
    $this->setting('deposit_bonus_percent', 100);
    $this->setting('deposit_bonus_max_amount', 5000);
    $this->setting('deposit_bonus_min_deposit', 100);
    $this->setting('deposit_bonus_scope', 'first');
    $this->setting('deposit_bonus_valid_days', 30);
});

function approvedDeposit(\App\Models\User $user, float $amount): AgentOrder
{
    return AgentOrder::query()->create([
        'user_id' => $user->id,
        'type' => 'deposit',
        'amount' => $amount,
        'status' => 'success',
        'reference' => 'DEP-'.$user->id.'-'.uniqid(),
    ]);
}

it('pays the bonus into play-only credit, never into the withdrawable balance', function () {
    $user = player(200, 200);
    $order = approvedDeposit($user, 200);

    $grant = app(DepositBonusService::class)->grantForDeposit($user, $order, 200);

    expect($grant)->toBeInstanceOf(DepositBonusGrant::class)
        ->and((float) $grant->amount)->toBe(200.0);

    $user->refresh();
    expect((float) $user->credits)->toBe(400.0)
        ->and((float) $user->withdrawable_balance)->toBe(200.0);

    $ledger = WalletTransaction::query()->where('type', 'bonus')->first();
    expect($ledger)->not->toBeNull()
        ->and($ledger->metadata['withdrawable'])->toBeFalse();
});

it('pays once per deposit order however often the approval is replayed', function () {
    $user = player(200, 200);
    $order = approvedDeposit($user, 200);
    $service = app(DepositBonusService::class);

    $service->grantForDeposit($user, $order, 200);
    expect($service->grantForDeposit($user->fresh(), $order, 200))->toBeNull();

    expect(DepositBonusGrant::query()->count())->toBe(1)
        ->and((float) $user->fresh()->credits)->toBe(400.0);
});

it('pays nothing below the minimum deposit and caps the bonus at the maximum', function () {
    $service = app(DepositBonusService::class);

    $small = player(50, 50);
    expect($service->grantForDeposit($small, approvedDeposit($small, 50), 50))->toBeNull()
        ->and((float) $small->fresh()->credits)->toBe(50.0);

    $this->setting('deposit_bonus_max_amount', 300);
    $large = player(1000, 1000);
    $grant = $service->grantForDeposit($large, approvedDeposit($large, 1000), 1000);
    expect((float) $grant->amount)->toBe(300.0)
        ->and((float) $large->fresh()->credits)->toBe(1300.0);
});

it('pays nothing when the bonus is switched off', function () {
    $this->setting('deposit_bonus_enabled', 0);
    $user = player(200, 200);

    expect(app(DepositBonusService::class)->grantForDeposit($user, approvedDeposit($user, 200), 200))->toBeNull()
        ->and((float) $user->fresh()->credits)->toBe(200.0);
});
