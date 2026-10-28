<?php

use App\Models\User;
use App\Models\WinhouseSession;
use App\Models\WinhouseTransaction;

/*
 * The sportsbook wallet callbacks: token and signature checks, the
 * idempotency rule, and the bonus split on a bet and its win.
 */

const WH_TOKEN = 'callback-token-for-tests-0123456789abcdef';
const WH_API_KEY = 'api-key-for-tests-0123456789abcdef';

beforeEach(function () {
    config([
        'services.winhouse.callback_token' => WH_TOKEN,
        'services.winhouse.api_key' => WH_API_KEY,
        'services.winhouse.mode' => 'live',
        'services.winhouse.currency' => 'ETB',
    ]);
});

function bookSession(User $user): WinhouseSession
{
    return WinhouseSession::query()->create([
        'user_id' => $user->id,
        'external_session_id' => 'sess-'.$user->id,
        'mode' => 'live',
        'status' => 'active',
        'expires_at' => now()->addHours(2),
    ]);
}

/** A balance-change payload signed the way WinHouse sign theirs (docs §4.3). */
function signedChange(User $user, int|float $amount, string $action, string $ticket, string $transactionId): array
{
    $wire = is_float($amount) && floor($amount) !== $amount ? (string) $amount : (string) (int) $amount;
    $payload = [
        'username' => 'player_'.$user->id,
        'amount' => $amount,
        'action' => $action,
        'ticket_id' => $ticket,
        'transaction_id' => $transactionId,
        'session' => 'sess-'.$user->id,
    ];
    $payload['signature'] = hash_hmac('sha256', $payload['username'].$wire.$action.$ticket.$transactionId, WH_API_KEY);

    return $payload;
}

function postChange(array $payload, string $token = WH_TOKEN)
{
    return test()->postJson('/api/integrations/winhouse/balance-change?token='.$token, $payload);
}

it('refuses a callback without the right token, and fails closed when none is configured', function () {
    $user = player(100);

    test()->getJson('/api/integrations/winhouse/user-data?token=wrong&username=player_'.$user->id)->assertStatus(401);

    config(['services.winhouse.callback_token' => '']);
    test()->getJson('/api/integrations/winhouse/user-data?token=&username=player_'.$user->id)->assertStatus(401);
});

it('reports the balance for user-data', function () {
    $user = player(250.5);

    test()->getJson('/api/integrations/winhouse/user-data?token='.WH_TOKEN.'&username=player_'.$user->id)
        ->assertOk()
        ->assertJson(['username' => 'player_'.$user->id, 'balance' => '250.50', 'currency' => 'ETB']);
});

it('refuses a balance change with a bad signature', function () {
    $user = player(100);
    bookSession($user);

    $payload = signedChange($user, -25, 'bet', 'T1', 'tx-1');
    $payload['signature'] = str_repeat('0', 64);

    postChange($payload)->assertStatus(401);
    expect((float) $user->fresh()->credits)->toBe(100.0);
});

it('debits a bet, credits a win, and replays the same transaction without applying it twice', function () {
    $user = player(100);
    bookSession($user);

    postChange(signedChange($user, -25, 'bet', 'T1', 'tx-bet-1'))
        ->assertOk()->assertJson(['status' => 'ok', 'balance' => '75.00']);

    // The book retries the same callback: the original balance comes back, nothing moves.
    postChange(signedChange($user, -25, 'bet', 'T1', 'tx-bet-1'))
        ->assertOk()->assertJson(['balance' => '75.00', 'duplicate' => true]);
    expect(WinhouseTransaction::query()->count())->toBe(1)
        ->and((float) $user->fresh()->credits)->toBe(75.0);

    // The same id with a different amount is a conflict, not a guess.
    postChange(signedChange($user, -40, 'bet', 'T1', 'tx-bet-1'))->assertStatus(409);

    postChange(signedChange($user, 60, 'win', 'T1', 'tx-win-1'))
        ->assertOk()->assertJson(['balance' => '135.00']);

    $user->refresh();
    expect((float) $user->credits)->toBe(135.0)
        ->and((float) $user->withdrawable_balance)->toBe(135.0);
});

it('refuses a bet the wallet cannot cover', function () {
    $user = player(10);
    bookSession($user);

    postChange(signedChange($user, -25, 'bet', 'T2', 'tx-big'))->assertStatus(422);
    expect((float) $user->fresh()->credits)->toBe(10.0);
});

it('spends bonus credit first and returns it as bonus when the ticket wins', function () {
    $user = player(100, 40);                       // 60 play-only, 40 withdrawable
    bookSession($user);

    postChange(signedChange($user, -50, 'bet', 'T3', 'tx-b3'))->assertOk();
    $user->refresh();
    expect((float) $user->credits)->toBe(50.0)
        ->and((float) $user->withdrawable_balance)->toBe(40.0);   // the stake came out of bonus

    // Wins 100: 50 of it is the bonus coming back, 50 is winnings.
    postChange(signedChange($user, 100, 'win', 'T3', 'tx-w3'))->assertOk()->assertJson(['balance' => '150.00']);
    $user->refresh();
    expect((float) $user->credits)->toBe(150.0)
        ->and((float) $user->withdrawable_balance)->toBe(90.0);
});

it('rejects a balance change when the book session has expired', function () {
    $user = player(100);
    bookSession($user)->update(['expires_at' => now()->subMinute()]);

    postChange(signedChange($user, -25, 'bet', 'T4', 'tx-exp'))->assertStatus(403);
});
