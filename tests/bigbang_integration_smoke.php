<?php

use App\Models\BigBangSession;
use App\Models\BigBangTransaction;
use App\Models\Game;
use App\Models\GameRound;
use App\Models\User;
use App\Services\BigBangCasinoClient;
use App\Services\BigBangCatalogSynchronizer;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->instance('request', Request::create('/'));
$app->make(Kernel::class)->bootstrap();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

config([
    'services.bigbang.base_url' => 'https://api.bigbangcasino.bet/api/v1',
    'services.bigbang.mode' => 'sandbox',
    'services.bigbang.currency' => 'EUR',
    'services.bigbang.provider' => 'Endorphina',
]);

Http::fake([
    'https://api.bigbangcasino.bet/api/v1/games?*' => Http::response([
        'success' => true,
        'data' => [[
            'id' => 4821,
            'name' => 'Hot Hot Fruit',
            'provider' => 'Endorphina',
            'category' => 'Endorphina',
            'thumbnail' => 'https://bigbangcasino.bet/game.webp',
        ]],
        'pagination' => ['total' => 1, 'limit' => 120, 'offset' => 0],
    ], 200, ['Content-Type' => 'application/json']),
    'https://api.bigbangcasino.bet/api/v1/games/launch' => Http::response([
        'success' => true,
        'game_url' => 'https://bigbangcasino.bet/demo/launch/Habanero/SGHotHotFruit',
        'demo' => true,
        'game_id' => 4821,
    ], 200, ['Content-Type' => 'application/json']),
    'https://api.bigbangcasino.bet/api/v1/users/create' => Http::response([
        'success' => true,
        'data' => ['user_token' => 'player_1', 'currency' => 'ETB'],
    ], 201, ['Content-Type' => 'application/json']),
    'https://api.bigbangcasino.bet/api/v1/users/*' => Http::response([
        'success' => true,
        'data' => ['user_token' => 'player_1', 'currency' => 'ETB', 'balance' => 0],
    ], 200, ['Content-Type' => 'application/json']),
]);

$client = app(BigBangCasinoClient::class);
$catalog = $client->catalogPage();
$game = new Game(['provider_game_id' => '4821']);
$launch = $client->launch(
    $game,
    User::query()->firstOrFail(),
    'https://localhost/api/integrations/bigbang/user-data',
    'https://localhost'
);

$assert(($catalog['data'][0]['id'] ?? null) === 4821, 'Catalog adapter did not return the provider game.');
$assert(
    collect(Http::recorded())->contains(
        fn ($record) => str_contains((string) $record[0]->url(), 'provider=Endorphina')
    ),
    'Catalog request did not apply the configured provider scope.'
);
$assert(($launch['mode'] ?? null) === 'fun', 'Sandbox launch did not use fun mode.');
$assert(($launch['currency'] ?? null) === 'EUR', 'Sandbox launch currency was not preserved.');

DB::beginTransaction();
try {
    $sync = app(BigBangCatalogSynchronizer::class)->sync();
    $syncedGame = Game::query()->where('provider_game_id', '4821')->where('sync_source', 'bigbang')->firstOrFail();
    $assert(($sync['created'] + $sync['updated']) === 1, 'Catalog synchronizer did not process one game.');
    $assert($syncedGame->playable === true, 'Synchronized provider game was not published as playable.');
    $assert($syncedGame->external_launch_code === '4821', 'Synchronized game did not retain the numeric launch ID.');

    $user = User::query()->firstOrFail();
    BigBangSession::create([
        'user_id' => $user->id,
        'game_id' => $syncedGame->id,
        'external_session_id' => 'smoke-session',
        'mode' => 'real',
        'currency' => 'ETB',
        'status' => 'active',
        'expires_at' => now()->addHour(),
        'last_activity_at' => now(),
    ]);

    $callbackToken = str_repeat('c', 40);
    config([
        'services.bigbang.mode' => 'live',
        'services.bigbang.currency' => 'ETB',
        'services.bigbang.callback_token' => $callbackToken,
    ]);
    $provisioned = $client->provisionPlayer($user);
    $assert(data_get($provisioned, 'data.currency') === 'ETB', 'Player provisioning did not verify the ETB account currency.');

    $userDataRequest = Request::create(
        'https://localhost/api/integrations/bigbang/user-data?token='.$callbackToken.'&username=player_'.$user->id,
        'GET',
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $userDataResponse = $app->make(Kernel::class)->handle($userDataRequest);
    $userData = json_decode($userDataResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $assert($userDataResponse->getStatusCode() === 200, 'Seamless user_data callback was rejected.');
    $assert(($userData['currency'] ?? null) === 'ETB', 'Seamless user_data returned the wrong currency.');

    $apiKey = (string) (getenv('BIGBANG_API_KEY') ?: config('services.bigbang.api_key'));
    $payload = [
        'username' => 'player_'.$user->id,
        'amount' => '-5',
        'game' => 'Hot Hot Fruit',
        'game_category' => 'Habanero',
        'transaction_id' => 'smoke-bet-1',
    ];
    $payload['signature'] = hash_hmac(
        'sha256',
        $payload['username'].$payload['amount'].$payload['game'].$payload['game_category'].$payload['transaction_id'],
        $apiKey
    );
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $playerBalanceBefore = $user->credits;
    $request = Request::create(
        'https://localhost/api/integrations/bigbang/balance-change?token='.$callbackToken,
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $raw
    );
    $response = $app->make(Kernel::class)->handle($request);
    $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    $assert($response->getStatusCode() === 200, 'Signed seamless balance_change callback was rejected.');
    $assert(($json['balance'] ?? null) === number_format($playerBalanceBefore - 5, 2, '.', ''), 'Live callback returned the wrong balance.');
    $assert((int) User::query()->findOrFail($user->id)->credits === (int) $playerBalanceBefore - 5, 'Live callback did not debit the ETB wallet.');

    $duplicateResponse = $app->make(Kernel::class)->handle($request);
    $assert($duplicateResponse->getStatusCode() === 200, 'Duplicate callback was not idempotent.');
    $assert((int) User::query()->findOrFail($user->id)->credits === (int) $playerBalanceBefore - 5, 'Duplicate callback debited the wallet twice.');

    // The stake must be visible as a game round, otherwise the deduction looks like it
    // happened without any game being played.
    $betRound = GameRound::query()
        ->where('user_id', $user->id)
        ->where('provider', 'bigbang')
        ->where('external_round_id', 'smoke-bet-1')
        ->firstOrFail();
    $assert((float) $betRound->stake === 5.0, 'The provider stake was not recorded as a game round.');
    $assert((float) $betRound->payout === 0.0, 'A fresh provider round should not carry a payout.');
    $assert(
        BigBangTransaction::query()->where('provider_transaction_id', 'smoke-bet-1')->where('action', 'bet')->value('game_round_id') === $betRound->id,
        'The provider bet was not linked to its game round.'
    );

    // Providers reuse the round's transaction id for the settlement. This used to answer
    // HTTP 409 and silently swallow the payout.
    $signedCallback = static function (array $fields) use ($apiKey, $callbackToken): Request {
        $fields['signature'] = hash_hmac(
            'sha256',
            $fields['username'].$fields['amount'].$fields['game'].$fields['game_category'].$fields['transaction_id'],
            $apiKey
        );

        return Request::create(
            'https://localhost/api/integrations/bigbang/balance-change?token='.$callbackToken,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($fields, JSON_THROW_ON_ERROR)
        );
    };

    $winRequest = $signedCallback([
        'username' => 'player_'.$user->id,
        'amount' => '7.50',
        'game' => 'Hot Hot Fruit',
        'game_category' => 'Habanero',
        'transaction_id' => 'smoke-bet-1',
    ]);
    $winResponse = $app->make(Kernel::class)->handle($winRequest);
    $winJson = json_decode($winResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $assert($winResponse->getStatusCode() === 200, 'A win that reuses the bet transaction id was rejected.');
    $assert(
        (float) User::query()->findOrFail($user->id)->credits === (float) $playerBalanceBefore - 5 + 7.5,
        'The provider payout did not reach the player wallet.'
    );
    $assert(($winJson['balance'] ?? null) === number_format((float) $playerBalanceBefore - 5 + 7.5, 2, '.', ''), 'The settled balance echoed to the provider was wrong.');
    $assert((float) $betRound->fresh()->payout === 7.5, 'The provider payout was not attached to the open round.');

    $winReplay = $app->make(Kernel::class)->handle($winRequest);
    $assert($winReplay->getStatusCode() === 200, 'Replaying a settlement was not idempotent.');
    $assert(
        (float) User::query()->findOrFail($user->id)->credits === (float) $playerBalanceBefore - 5 + 7.5,
        'Replaying a settlement credited the wallet twice.'
    );

    // A different amount under an id already used for the same action is a real conflict.
    $conflict = $app->make(Kernel::class)->handle($signedCallback([
        'username' => 'player_'.$user->id,
        'amount' => '9.00',
        'game' => 'Hot Hot Fruit',
        'game_category' => 'Habanero',
        'transaction_id' => 'smoke-bet-1',
    ]));
    $assert($conflict->getStatusCode() === 409, 'A conflicting settlement amount was accepted.');

    // With the session closed the provider can no longer touch the wallet.
    BigBangSession::query()->where('external_session_id', 'smoke-session')->update(['status' => 'closed']);
    $balanceAfterSettlement = (float) User::query()->findOrFail($user->id)->credits;
    $afterClose = $app->make(Kernel::class)->handle($signedCallback([
        'username' => 'player_'.$user->id,
        'amount' => '-5',
        'game' => 'Hot Hot Fruit',
        'game_category' => 'Habanero',
        'transaction_id' => 'smoke-bet-after-close',
    ]));
    $assert($afterClose->getStatusCode() === 403, 'A callback was accepted after the provider session closed.');
    $assert(
        (float) User::query()->findOrFail($user->id)->credits === $balanceAfterSettlement,
        'A callback debited the wallet after the provider session closed.'
    );
    BigBangSession::query()->where('external_session_id', 'smoke-session')->update(['status' => 'active']);

    $payload['signature'] = str_repeat('0', 64);
    $invalidRequest = Request::create(
        'https://localhost/api/integrations/bigbang/balance-change?token='.$callbackToken,
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        json_encode($payload, JSON_THROW_ON_ERROR)
    );
    $invalidResponse = $app->make(Kernel::class)->handle($invalidRequest);
    $assert($invalidResponse->getStatusCode() === 401, 'Invalid callback signature was not rejected.');
} finally {
    DB::rollBack();
}

echo "BigBang integration smoke test passed.\n";
