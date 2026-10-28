<?php

use App\Models\User;
use App\Services\Wallet\LedgerEntry;
use App\Services\Wallet\PlayerWallet;

/*
 * Outside a transaction there is no row lock, so the wallet refuses to move.
 * (Unit, not Feature: the feature tests run inside RefreshDatabase's own
 * transaction, where this guard is always satisfied.)
 */

it('refuses to move money or take a lock outside a transaction', function () {
    $wallet = app(PlayerWallet::class);
    $user = new User(['credits' => 100, 'withdrawable_balance' => 100]);
    $entry = new LedgerEntry(method: 'test', reason: 'test');

    expect(fn () => $wallet->stake($user, 10, 'qtech', 'r1', $entry))->toThrow(LogicException::class)
        ->and(fn () => $wallet->payout($user, 10, 'qtech', 'r1', $entry))->toThrow(LogicException::class)
        ->and(fn () => $wallet->refund($user, 10, 0, 'qtech', 'r1', $entry))->toThrow(LogicException::class)
        ->and(fn () => $wallet->reward($user, 10, $entry))->toThrow(LogicException::class)
        ->and(fn () => $wallet->playRound($user, 10, 0, $entry, $entry))->toThrow(LogicException::class)
        ->and(fn () => $wallet->lock(1))->toThrow(LogicException::class);
});
