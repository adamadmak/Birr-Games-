<?php

use App\Models\QtechSession;
use App\Models\QtechTransaction;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Models\WinhouseSession;

/*
 * The shapes a signed or keyed callback must refuse: a staging key naming a
 * live player, a rollback naming the wrong player, a signed WinHouse payload
 * re-cut into a different one, and a Telegram update without the secret.
 */

const HARD_QT_KEY = 'qtech-pass-key-for-tests-0123456789';
const HARD_QT_STAGING = 'qtech-staging-key-for-tests-0123456';
const HARD_WH_TOKEN = 'callback-token-for-tests-0123456789abcdef';
const HARD_WH_API = 'api-key-for-tests-0123456789abcdef';

beforeEach(function () {
    config([
        'services.qtech.pass_key' => HARD_QT_KEY,
        'services.qtech.pass_key_staging' => HARD_QT_STAGING,
        'services.qtech.currency' => 'ETB',
        'services.winhouse.callback_token' => HARD_WH_TOKEN,
        'services.winhouse.api_key' => HARD_WH_API,
        'services.winhouse.mode' => 'live',
    ]);
});

function hardSession(User $user): void
{
    QtechSession::query()->create(['user_id' => $user->id, 'external_session_id' => 'qt-'.$user->id, 'currency' => 'ETB', 'status' => 'active', 'expires_at' => now()->addHour()]);
}

it('refuses a staging-key callback for any player but the fixture', function () {
    $live = player(100);
    hardSession($live);

    $payload = ['txnType' => 'CREDIT', 'txnId' => 'stg-1', 'playerId' => 'player_'.$live->id, 'roundId' => 'r1', 'amount' => 500, 'currency' => 'ETB', 'completed' => true];

    test()->postJson('/api/integrations/qtech-staging/transactions', $payload, ['Pass-Key' => HARD_QT_STAGING])
        ->assertStatus(400)->assertJson(['code' => 'REQUEST_DECLINED']);
    expect((float) $live->fresh()->credits)->toBe(100.0);

    // The same key is not accepted on the production prefix either.
    test()->postJson('/api/integrations/qtech/transactions', $payload, ['Pass-Key' => HARD_QT_STAGING])->assertStatus(401);

    // The fixture player is the one account staging may touch.
    $fixture = User::factory()->create(['username' => \App\Services\QtechTestCredentials::PLAYER, 'credits' => 0, 'withdrawable_balance' => 0]);
    test()->postJson('/api/integrations/qtech-staging/transactions', ['playerId' => 'player_'.$fixture->id, 'txnId' => 'stg-2'] + $payload, ['Pass-Key' => HARD_QT_STAGING])
        ->assertStatus(201);
    expect((float) $fixture->fresh()->credits)->toBe(500.0);
});

it('refunds a rolled-back bet only to the player who placed it', function () {
    $owner = player(100);
    $other = player(100);
    hardSession($owner);

    test()->postJson('/api/integrations/qtech/transactions', ['txnType' => 'DEBIT', 'txnId' => 'bet-x', 'playerId' => 'player_'.$owner->id, 'roundId' => 'r1', 'amount' => 40, 'currency' => 'ETB'], ['Pass-Key' => HARD_QT_KEY, 'Wallet-Session' => 'qt-'.$owner->id])
        ->assertStatus(201);

    test()->postJson('/api/integrations/qtech/transactions/rollback', ['txnId' => 'rb-x', 'betId' => 'bet-x', 'playerId' => 'player_'.$other->id], ['Pass-Key' => HARD_QT_KEY])
        ->assertStatus(400)->assertJson(['code' => 'REQUEST_DECLINED']);

    expect((float) $other->fresh()->credits)->toBe(100.0)
        ->and((float) $owner->fresh()->credits)->toBe(60.0)
        ->and(QtechTransaction::query()->where('type', 'ROLLBACK')->count())->toBe(0);
});

describe('WinHouse payload shape', function () {
    function whSign(array $payload, string $wire): array
    {
        $payload['signature'] = hash_hmac('sha256', $payload['username'].$wire.$payload['action'].$payload['ticket_id'].$payload['transaction_id'], HARD_WH_API);

        return $payload;
    }

    it('refuses a re-cut player id, a missing action, a mismatched sign and extra decimals', function () {
        $user = player(100);
        WinhouseSession::query()->create(['user_id' => $user->id, 'external_session_id' => 'sess-'.$user->id, 'mode' => 'live', 'status' => 'active', 'expires_at' => now()->addHour()]);
        $base = ['username' => 'player_'.$user->id, 'amount' => -25, 'action' => 'bet', 'ticket_id' => 'T100', 'transaction_id' => 'tx-1', 'session' => 'sess-'.$user->id];
        $post = fn (array $p) => test()->postJson('/api/integrations/winhouse/balance-change?token='.HARD_WH_TOKEN, $p);

        // The honest payload works.
        $post(whSign($base, '-25'))->assertOk()->assertJson(['balance' => '75.00']);

        // The same signed material re-cut: "player_7-" + "25" + "" + "betT100" + "tx-1".
        $recut = ['username' => $base['username'].'-', 'amount' => 25, 'action' => '', 'ticket_id' => 'bet'.$base['ticket_id'], 'transaction_id' => 'tx-1', 'session' => $base['session']];
        $recut['signature'] = hash_hmac('sha256', $recut['username'].'25'.''.$recut['ticket_id'].$recut['transaction_id'], HARD_WH_API);
        $post($recut)->assertStatus(404);

        // Declared action and sign must agree.
        $post(whSign(['transaction_id' => 'tx-2', 'action' => 'win'] + $base, '-25'))->assertStatus(422);
        $post(whSign(['amount' => 25, 'transaction_id' => 'tx-3'] + $base, '25'))->assertStatus(422);   // 'bet' with a positive amount

        // More than two decimals is not an amount a book sends.
        $post(whSign(['amount' => -25.005, 'transaction_id' => 'tx-4'] + $base, '-25.005'))->assertStatus(422);

        expect((float) $user->fresh()->credits)->toBe(75.0);
    });
});

it('writes nothing for a Telegram update that does not carry the webhook secret', function () {
    config(['services.telegram.webhook_secret' => 'webhook-secret-for-tests-0123456789']);

    test()->postJson('/api/integrations/telegram/webhook', ['update_id' => 4242, 'message' => ['text' => '/start']])
        ->assertStatus(403);

    expect(TelegramUpdate::query()->where('update_id', 4242)->exists())->toBeFalse();
});
