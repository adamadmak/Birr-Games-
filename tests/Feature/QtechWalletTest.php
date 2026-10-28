<?php

use App\Models\QtechSession;
use App\Models\QtechTransaction;
use App\Models\User;
use App\Models\WalletTransaction;

/*
 * The QTech Common Wallet callbacks: pass key, the txnId idempotency rule,
 * a bet, its win, its rollback, a reward, and the bonus split through all of them.
 */

const QT_PASS_KEY = 'qtech-pass-key-for-tests-0123456789';

beforeEach(function () {
    config(['services.qtech.pass_key' => QT_PASS_KEY, 'services.qtech.currency' => 'ETB']);
});

function qtSession(User $user): QtechSession
{
    return QtechSession::query()->create([
        'user_id' => $user->id,
        'external_session_id' => 'qt-sess-'.$user->id,
        'currency' => 'ETB',
        'status' => 'active',
        'expires_at' => now()->addHours(2),
    ]);
}

function qt(string $path, array $payload, ?User $user = null, string $passKey = QT_PASS_KEY)
{
    $headers = ['Pass-Key' => $passKey];
    if ($user) {
        $headers['Wallet-Session'] = 'qt-sess-'.$user->id;
    }

    return test()->postJson('/api/integrations/qtech'.$path, $payload, $headers);
}

function debit(User $user, string $txn, float $amount, string $round = 'round-1'): array
{
    return ['txnType' => 'DEBIT', 'txnId' => $txn, 'playerId' => 'player_'.$user->id, 'roundId' => $round, 'amount' => $amount, 'currency' => 'ETB', 'gameId' => 'G1'];
}

function credit(User $user, string $txn, float $amount, string $round = 'round-1'): array
{
    return ['txnType' => 'CREDIT', 'txnId' => $txn, 'playerId' => 'player_'.$user->id, 'roundId' => $round, 'amount' => $amount, 'currency' => 'ETB', 'gameId' => 'G1', 'completed' => true];
}

it('refuses a wrong pass key and fails closed without one', function () {
    $user = player(100);
    qtSession($user);

    qt('/transactions', debit($user, 'tx-1', 10), $user, 'wrong')->assertStatus(401)->assertJson(['code' => 'LOGIN_FAILED']);

    config(['services.qtech.pass_key' => '']);
    qt('/transactions', debit($user, 'tx-1', 10), $user, '')->assertStatus(401);
});

it('answers the balance for a live session and refuses a stale one', function () {
    $user = player(42.5);
    $session = qtSession($user);

    test()->getJson('/api/integrations/qtech/accounts/player_'.$user->id.'/session?gameId=G1', ['Pass-Key' => QT_PASS_KEY, 'Wallet-Session' => $session->external_session_id])
        ->assertOk()->assertJson(['balance' => 42.5, 'currency' => 'ETB']);

    $session->update(['expires_at' => now()->subMinute()]);
    test()->getJson('/api/integrations/qtech/accounts/player_'.$user->id.'/session?gameId=G1', ['Pass-Key' => QT_PASS_KEY, 'Wallet-Session' => $session->external_session_id])
        ->assertStatus(400)->assertJson(['code' => 'INVALID_TOKEN']);
});

it('takes a bet, pays its win, and replays both without moving money twice', function () {
    $user = player(100);
    qtSession($user);

    qt('/transactions', debit($user, 'bet-1', 30), $user)->assertStatus(201)->assertJson(['balance' => 70]);
    qt('/transactions', debit($user, 'bet-1', 30), $user)->assertStatus(201)->assertJson(['balance' => 70]);   // QT retry

    qt('/transactions', credit($user, 'win-1', 90), $user)->assertStatus(201)->assertJson(['balance' => 160]);
    qt('/transactions', credit($user, 'win-1', 90), $user)->assertStatus(201)->assertJson(['balance' => 160]);

    $user->refresh();
    expect((float) $user->credits)->toBe(160.0)
        ->and((float) $user->withdrawable_balance)->toBe(160.0)
        ->and(QtechTransaction::query()->count())->toBe(2)
        ->and(WalletTransaction::query()->where('method', 'qtech')->count())->toBe(2);

    // The ledger rows point at the QTech rows they settle.
    $ledger = WalletTransaction::query()->where('ledger_key', 'qtech-debit-bet-1')->first();
    expect($ledger->source_type)->toBe('qtech_transaction')
        ->and($ledger->source_id)->toBe(QtechTransaction::query()->where('txn_id', 'bet-1')->value('id'));
});

it('refuses a bet the wallet cannot cover', function () {
    $user = player(10);
    qtSession($user);

    qt('/transactions', debit($user, 'bet-big', 25), $user)->assertStatus(400)->assertJson(['code' => 'INSUFFICIENT_FUNDS']);
    expect((float) $user->fresh()->credits)->toBe(10.0)
        ->and(QtechTransaction::query()->count())->toBe(0);
});

it('refuses a bet without a live session but still pays a win after the session lapsed', function () {
    $user = player(100);
    $session = qtSession($user);

    qt('/transactions', debit($user, 'bet-2', 20), $user)->assertStatus(201);
    $session->update(['expires_at' => now()->subMinute()]);

    qt('/transactions', debit($user, 'bet-3', 20), $user)->assertStatus(400)->assertJson(['code' => 'INVALID_TOKEN']);
    qt('/transactions', credit($user, 'win-2', 50), $user)->assertStatus(201)->assertJson(['balance' => 130]);
});

it('rolls a bet back the way it was paid, once', function () {
    $user = player(100, 40);                       // 60 bonus, 40 cash
    qtSession($user);

    qt('/transactions', debit($user, 'bet-4', 90), $user)->assertStatus(201);   // 60 bonus + 30 cash
    $user->refresh();
    expect((float) $user->withdrawable_balance)->toBe(10.0);

    $rollback = ['txnId' => 'rb-1', 'betId' => 'bet-4', 'playerId' => 'player_'.$user->id, 'roundId' => 'round-1'];
    qt('/transactions/rollback', $rollback)->assertOk()->assertJson(['balance' => 100]);
    $user->refresh();
    expect((float) $user->credits)->toBe(100.0)
        ->and((float) $user->withdrawable_balance)->toBe(40.0);

    // A second rollback of the same bet under a new id is a successful no-op.
    qt('/transactions/rollback', ['txnId' => 'rb-2', 'betId' => 'bet-4', 'playerId' => 'player_'.$user->id])->assertOk()->assertJson(['balance' => 100]);
    expect((float) $user->fresh()->credits)->toBe(100.0);

    // And a rollback of a bet we never saw is a success too, per spec.
    qt('/transactions/rollback', ['txnId' => 'rb-3', 'betId' => 'never', 'playerId' => 'player_'.$user->id])->assertOk()->assertJson(['balance' => 100]);
});

it('keeps the bonus part of a stake play-only when the round pays', function () {
    $user = player(100, 40);
    qtSession($user);

    qt('/transactions', debit($user, 'bet-5', 50, 'round-9'), $user)->assertStatus(201);
    qt('/transactions', credit($user, 'win-5', 100, 'round-9'), $user)->assertStatus(201)->assertJson(['balance' => 150]);

    $user->refresh();
    expect((float) $user->credits)->toBe(150.0)
        ->and((float) $user->withdrawable_balance)->toBe(90.0);   // 40 + 100 - 50 bonus returned
});

it('pays a reward as cash and a zero-amount settlement moves nothing', function () {
    $user = player(10, 0);
    qtSession($user);

    qt('/bonus/rewards', ['txnId' => 'rw-1', 'rewardType' => 'TOURNAMENT', 'playerId' => 'player_'.$user->id, 'amount' => 25])
        ->assertStatus(201)->assertJson(['balance' => 35]);
    expect((float) $user->fresh()->withdrawable_balance)->toBe(25.0);

    qt('/transactions', credit($user, 'win-0', 0, 'round-lost'), $user)->assertStatus(201)->assertJson(['balance' => 35]);
    expect(WalletTransaction::query()->where('ledger_key', 'qtech-credit-win-0')->count())->toBe(0);
});

it('refuses a transaction id reused for another player', function () {
    $a = player(100);
    $b = player(100);
    qtSession($a);
    qtSession($b);

    qt('/transactions', debit($a, 'shared', 10), $a)->assertStatus(201);
    qt('/transactions', debit($b, 'shared', 10), $b)->assertStatus(400)->assertJson(['code' => 'REQUEST_DECLINED']);
    expect((float) $b->fresh()->credits)->toBe(100.0);
});
