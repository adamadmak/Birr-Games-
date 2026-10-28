<?php

use App\Services\BonusPlay;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;

/*
 * The one rule that keeps bonus credit from becoming cash: whatever part of a
 * round was staked from bonus comes back as bonus, and only the winnings
 * become withdrawable.
 */

it('returns the whole bonus stake when the round pays at least the stake', function () {
    // 50 staked, all of it bonus, paid 120: the 50 goes back to play-only.
    expect(BonusPlay::lockedShare(50, 50, 120))->toBe(50.0);
    // Paid exactly the stake (a void): same.
    expect(BonusPlay::lockedShare(50, 50, 50))->toBe(50.0);
});

it('returns the bonus proportionally when the round pays less than the stake', function () {
    // Half bonus, half cash, paid back 40 of 100: 20 of that is bonus.
    expect(BonusPlay::lockedShare(100, 50, 40))->toBe(20.0);
});

it('locks nothing for an all-cash stake', function () {
    expect(BonusPlay::lockedShare(100, 0, 500))->toBe(0.0);
});

it('never locks more than was staked from bonus, however large the payout', function () {
    expect(BonusPlay::lockedShare(10, 10, 10_000))->toBe(10.0);
    // A bonus figure above the stake is clamped to the stake.
    expect(BonusPlay::lockedShare(10, 25, 10_000))->toBe(10.0);
});

it('measures a one-step round the same way', function () {
    // 1.01x on a 100 bonus stake: 100 stays bonus, 1 becomes cash.
    expect(BonusPlay::immediate(100, 0, 101))->toBe(100.0);
    // A losing round locks nothing.
    expect(BonusPlay::immediate(100, 0, 0))->toBe(0.0);
});

describe('propagates()', function () {
    $query = fn (string $message) => new QueryException('sqlite', 'select 1', [], new RuntimeException($message));

    it('rethrows a deadlock', function () use ($query) {
        expect(BonusPlay::propagates(new DeadlockException('Deadlock found when trying to get lock')))->toBeTrue();
        expect(BonusPlay::propagates($query('SQLSTATE[HY000]: General error: 1213 Deadlock found')))->toBeTrue();
        expect(BonusPlay::propagates($query('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded')))->toBeTrue();
        expect(BonusPlay::propagates($query('database is locked')))->toBeTrue();
    });

    it('swallows a schema that is behind, which is the old behaviour by design', function () use ($query) {
        expect(BonusPlay::propagates($query('SQLSTATE[HY000]: General error: 1 no such table: bonus_stakes')))->toBeFalse();
        expect(BonusPlay::propagates($query("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'x.bonus_stakes' doesn't exist")))->toBeFalse();
        expect(BonusPlay::propagates($query("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'bonus_returned'")))->toBeFalse();
    });

    it('swallows an ordinary failure outside a transaction and anything that is not a database error', function () use ($query) {
        expect(BonusPlay::propagates($query('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')))->toBeFalse();
        expect(BonusPlay::propagates(new RuntimeException('anything else')))->toBeFalse();
    });
});
