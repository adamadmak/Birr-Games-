<?php

use App\Models\BigBangSession;
use App\Models\BigBangTransaction;
use App\Models\Game;
use App\Models\GameCategory;
use App\Models\GameProvider;
use App\Models\User;

/*
 * The BigBang wallet callbacks: token, signature, idempotency, and the bonus
 * split on a bet and its win.
 */

const BB_TOKEN = 'bigbang-callback-token-for-tests-0123456789';
const BB_API_KEY = 'bigbang-api-key-for-tests';

beforeEach(function () {
    config([
        'services.bigbang.callback_token' => BB_TOKEN,
        'services.bigbang.api_key' => BB_API_KEY,
        'services.bigbang.mode' => 'live',
        'services.bigbang.currency' => 'ETB',
    ]);
});

function bbGame(): Game
{
    $provider = GameProvider::query()->firstOrCreate(['slug' => 'bb-studio'], ['name' => 'BB Studio']);
    $category = GameCategory::query()->firstOrCreate(['slug' => 'bb-slots'], ['name' => 'Slots']);

    return Game::query()->firstOrCreate(['slug' => 'bb-game'], [
        'provider_id' => $provider->id, 'category_id' => $category->id, 'title' => 'BB Game',
        'sync_source' => 'bigbang', 'provider_game_id' => 'bbg-1', 'active' => true, 'playable' => true,
    ]);
}

function bbSession(User $user): BigBangSession
{
    return BigBangSession::query()->create([
        'user_id' => $user->id,
        'game_id' => bbGame()->id,
        'external_session_id' => 'bb-sess-'.$user->id,
        'mode' => 'real',
        'currency' => 'ETB',
        'status' => 'active',
        'expires_at' => now()->addHours(2),
        'last_activity_at' => now(),
    ]);
}

function bbChange(User $user, string $amount, string $transactionId): array
{
    $payload = ['username' => 'player_'.$user->id, 'amount' => $amount, 'game' => '', 'game_category' => '', 'transaction_id' => $transactionId];
    $payload['signature'] = hash_hmac('sha256', $payload['username'].$amount.''.''.$transactionId, BB_API_KEY);

    return $payload;
}

function bbPost(array $payload, string $token = BB_TOKEN)
{
    return test()->postJson('/api/integrations/bigbang/balance-change?token='.$token, $payload);
}

it('refuses the wrong token and fails closed when none is configured', function () {
    $user = player(100);
    bbSession($user);

    bbPost(bbChange($user, '-10', 'tx-1'), 'wrong')->assertStatus(401);
    config(['services.bigbang.callback_token' => '']);
    bbPost(bbChange($user, '-10', 'tx-1'), '')->assertStatus(401);
});

it('only reports a balance for a player who is in a game', function () {
    $user = player(75);

    test()->getJson('/api/integrations/bigbang/user-data?token='.BB_TOKEN.'&username=player_'.$user->id)->assertStatus(404);

    bbSession($user);
    test()->getJson('/api/integrations/bigbang/user-data?token='.BB_TOKEN.'&username=player_'.$user->id)
        ->assertOk()->assertJson(['balance' => '75.00', 'currency' => 'ETB']);
});

it('refuses a bad signature and a fractional amount with more than two decimals', function () {
    $user = player(100);
    bbSession($user);

    $bad = bbChange($user, '-10', 'tx-2');
    $bad['signature'] = str_repeat('a', 64);
    bbPost($bad)->assertStatus(401);

    bbPost(bbChange($user, '-10.005', 'tx-3'))->assertStatus(422);
    expect((float) $user->fresh()->credits)->toBe(100.0);
});

it('debits a bet, credits a win, and replays a duplicate without applying it twice', function () {
    $user = player(100);
    bbSession($user);

    bbPost(bbChange($user, '-25', 'tx-bet'))->assertOk()->assertJson(['balance' => '75.00']);
    bbPost(bbChange($user, '-25', 'tx-bet'))->assertOk()->assertJson(['balance' => '75.00']);
    bbPost(bbChange($user, '-30', 'tx-bet'))->assertStatus(409);

    // The settlement reuses the bet's id, which is why the table is unique on (action, id).
    bbPost(bbChange($user, '60', 'tx-bet'))->assertOk()->assertJson(['balance' => '135.00']);

    expect(BigBangTransaction::query()->count())->toBe(2)
        ->and((float) $user->fresh()->credits)->toBe(135.0)
        ->and((float) $user->fresh()->withdrawable_balance)->toBe(135.0);
});

it('refuses a bet the wallet cannot cover', function () {
    $user = player(10);
    bbSession($user);

    bbPost(bbChange($user, '-25', 'tx-big'))->assertStatus(422)->assertJson(['error' => 'Insufficient balance.']);
    expect((float) $user->fresh()->credits)->toBe(10.0);
});

it('spends bonus first and returns it as bonus on the win', function () {
    $user = player(100, 40);
    bbSession($user);

    bbPost(bbChange($user, '-50', 'tx-b'))->assertOk();
    expect((float) $user->fresh()->withdrawable_balance)->toBe(40.0);

    bbPost(bbChange($user, '100', 'tx-b'))->assertOk()->assertJson(['balance' => '150.00']);
    $user->refresh();
    expect((float) $user->credits)->toBe(150.0)
        ->and((float) $user->withdrawable_balance)->toBe(90.0);
});

it('refuses a settlement when the session has gone idle', function () {
    $user = player(100);
    bbSession($user)->update(['last_activity_at' => now()->subHours(2)]);

    bbPost(bbChange($user, '-25', 'tx-idle'))->assertStatus(403);
});
