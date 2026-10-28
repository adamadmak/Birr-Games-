<?php

use App\Models\BonusStake;
use App\Models\WalletTransaction;
use App\Services\Wallet\InsufficientFunds;
use App\Services\Wallet\LedgerEntry;
use App\Services\Wallet\PlayerWallet;
use Illuminate\Support\Facades\DB;

/*
 * The one place a wallet moves for play. Every provider path and the
 * built-in games call this, so the rules are pinned down here once.
 */

function entry(string $reason = 'test', ?string $key = null): LedgerEntry
{
    return new LedgerEntry(method: 'test', reason: $reason, key: $key, sourceType: 'test', sourceId: 1, metadata: ['k' => 'v']);
}

function inTransaction(Closure $work): mixed
{
    return DB::transaction($work);
}

it('spends bonus credit before cash on a stake', function () {
    $wallet = app(PlayerWallet::class);
    $user = player(100, 40);                       // 60 bonus, 40 cash

    $move = inTransaction(fn () => $wallet->stake($wallet->lock($user), 50, 'qtech', 'r1', entry('bet', 'k-bet-1')));

    expect($move->amount)->toBe(50.0)
        ->and($move->withdrawableDebit)->toBe(0.0)
        ->and($move->credits)->toBe(50.0)
        ->and($move->withdrawable)->toBe(40.0);

    $user->refresh();
    expect((float) $user->credits)->toBe(50.0)
        ->and((float) $user->withdrawable_balance)->toBe(40.0);

    // The ledger row carries the sign, the key and the reason.
    expect($move->ledger)->not->toBeNull()
        ->and((float) $move->ledger->amount)->toBe(-50.0)
        ->and($move->ledger->type)->toBe('bet')
        ->and($move->ledger->ledger_key)->toBe('k-bet-1')
        ->and($move->ledger->method)->toBe('test');

    // And BonusPlay knows the whole stake was bonus.
    expect((float) BonusStake::query()->where('round_ref', 'r1')->value('bonus_staked'))->toBe(50.0);
});

it('takes the rest from cash once the bonus is spent', function () {
    $wallet = app(PlayerWallet::class);
    $user = player(100, 40);

    $move = inTransaction(fn () => $wallet->stake($wallet->lock($user), 90, 'qtech', 'r2', entry()));

    expect($move->withdrawableDebit)->toBe(30.0)
        ->and($move->credits)->toBe(10.0)
        ->and($move->withdrawable)->toBe(10.0);
});

it('refuses a stake the wallet does not cover and moves nothing', function () {
    $wallet = app(PlayerWallet::class);
    $user = player(20, 20);

    $thrown = null;
    try {
        inTransaction(fn () => $wallet->stake($wallet->lock($user), 25, 'qtech', 'r3', entry()));
    } catch (InsufficientFunds $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->requested)->toBe(25.0)
        ->and($thrown->available)->toBe(20.0)
        ->and((float) $user->fresh()->credits)->toBe(20.0)
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('returns the bonus part of a stake as bonus when the round pays, and the rest as cash', function () {
    $wallet = app(PlayerWallet::class);
    $user = player(100, 40);

    inTransaction(fn () => $wallet->stake($wallet->lock($user), 50, 'qtech', 'r4', entry()));
    $move = inTransaction(fn () => $wallet->payout($wallet->lock($user), 120, 'qtech', 'r4', entry('win')));

    expect($move->bonusKept)->toBe(50.0)
        ->and($move->credits)->toBe(170.0)
        ->and($move->withdrawable)->toBe(110.0)   // 40 + 120 - 50
        ->and($move->ledger->type)->toBe('payout')
        ->and((float) $move->ledger->amount)->toBe(120.0);
});

it('makes a 1.01x win on a bonus stake worth one cent of cash, never the stake', function () {
    $wallet = app(PlayerWallet::class);
    $user = player(100, 0);                        // all bonus

    inTransaction(fn () => $wallet->stake($wallet->lock($user), 100, 'winhouse', 't1', entry()));
    $move = inTransaction(fn () => $wallet->payout($wallet->lock($user), 101, 'winhouse', 't1', entry()));

    expect($move->withdrawable)->toBe(1.0)
        ->and($move->credits)->toBe(101.0);
});

it('refunds a stake the way it came out', function () {
    $wallet = app(PlayerWallet::class);
    $user = player(100, 40);

    $bet = inTransaction(fn () => $wallet->stake($wallet->lock($user), 90, 'qtech', 'r5', entry()));   // 60 bonus + 30 cash
    $move = inTransaction(fn () => $wallet->refund($wallet->lock($user), 90, $bet->withdrawableDebit, 'qtech', 'r5', entry('rollback')));

    expect($move->credits)->toBe(100.0)
        ->and($move->withdrawable)->toBe(40.0)
        ->and($move->ledger->type)->toBe('refund')
        ->and(BonusStake::query()->where('round_ref', 'r5')->count())->toBe(0);
});

it('pays a reward as cash', function () {
    $wallet = app(PlayerWallet::class);
    $user = player(10, 0);

    $move = inTransaction(fn () => $wallet->reward($wallet->lock($user), 25, entry('prize')));

    expect($move->credits)->toBe(35.0)
        ->and($move->withdrawable)->toBe(25.0);
});

it('moves and writes nothing for a zero amount', function () {
    $wallet = app(PlayerWallet::class);
    $user = player(10, 10);

    $move = inTransaction(fn () => $wallet->stake($wallet->lock($user), 0, 'qtech', 'free', entry()));

    expect($move->amount)->toBe(0.0)
        ->and($move->ledger)->toBeNull()
        ->and((float) $user->fresh()->credits)->toBe(10.0)
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('settles a one-step round with the same split and two ledger rows', function () {
    $wallet = app(PlayerWallet::class);
    $user = player(100, 40);                       // 60 bonus

    $move = inTransaction(fn () => $wallet->playRound($wallet->lock($user), 50, 125, entry('stake'), entry('payout')));

    // 50 staked from bonus; the 125 payout returns 50 as bonus, 75 as cash.
    expect($move->bonusKept)->toBe(50.0)
        ->and($move->credits)->toBe(175.0)
        ->and($move->withdrawable)->toBe(115.0);

    expect(WalletTransaction::query()->pluck('type')->sort()->values()->all())->toBe(['bet', 'payout']);
});

it('rounds every movement to cents so paths cannot drift', function () {
    $wallet = app(PlayerWallet::class);
    $user = player(10, 10);

    inTransaction(fn () => $wallet->stake($wallet->lock($user), 0.1 + 0.2, 'qtech', 'r6', entry()));
    $move = inTransaction(fn () => $wallet->payout($wallet->lock($user), 1.005, 'qtech', 'r6', entry()));

    expect($move->credits)->toBe(10.71)
        ->and($move->withdrawable)->toBe(10.71);
});
