<?php

use App\Models\BonusStake;
use App\Services\BonusPlay;
use Illuminate\Support\Facades\DB;

/*
 * BonusPlay against the real bonus_stakes table: a round staked from bonus
 * returns the bonus part on payout, once, and a rollback takes it back out.
 */

it('remembers how much of a round was bonus and returns only that much on payout', function () {
    $user = player(100, 40);                       // 60 of the 100 is play-only

    BonusPlay::stake($user, 'winhouse', 'ticket:1', 50, 0);   // all 50 from bonus

    $row = BonusStake::query()->where('round_ref', 'ticket:1')->first();
    expect($row)->not->toBeNull()
        ->and((float) $row->stake_total)->toBe(50.0)
        ->and((float) $row->bonus_staked)->toBe(50.0);

    // The ticket wins 120: 50 stays bonus, 70 may be cashed out.
    expect(BonusPlay::payout($user, 'winhouse', 'ticket:1', 120))->toBe(50.0);

    // A second settlement on the same ticket has nothing more to lock.
    expect(BonusPlay::payout($user, 'winhouse', 'ticket:1', 30))->toBe(0.0);
});

it('writes no row for an all-cash stake and locks nothing when it pays', function () {
    $user = player(100, 100);

    BonusPlay::stake($user, 'qtech', 'round:7', 25, 25);

    expect(BonusStake::query()->count())->toBe(0)
        ->and(BonusPlay::payout($user, 'qtech', 'round:7', 80))->toBe(0.0);
});

it('takes a rolled-back stake out again', function () {
    $user = player(100, 40);

    BonusPlay::stake($user, 'qtech', 'round:9', 30, 0);
    BonusPlay::unstake($user, 'qtech', 'round:9', 30, 0);

    expect(BonusStake::query()->count())->toBe(0)
        ->and(BonusPlay::payout($user, 'qtech', 'round:9', 90))->toBe(0.0);
});

it('rethrows a lock failure raised inside an outer money transaction', function () {
    $user = player(100, 40);

    $thrown = null;
    try {
        DB::transaction(function () use ($user, &$thrown) {
            // Simulate what guarded() sees when the database kills the transaction.
            $exception = new \Illuminate\Database\DeadlockException('Deadlock found');
            expect(BonusPlay::propagates($exception))->toBeTrue();
            throw $exception;
        });
    } catch (\Illuminate\Database\DeadlockException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
});
