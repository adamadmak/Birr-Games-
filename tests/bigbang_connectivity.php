<?php

use App\Services\BigBangCasinoClient;
use App\Services\BigBangCatalogSynchronizer;
use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->instance('request', Request::create('/'));
$app->make(Kernel::class)->bootstrap();

try {
    $client = app(BigBangCasinoClient::class);
    if (($argv[1] ?? null) === '--sync') {
        $result = app(BigBangCatalogSynchronizer::class)->sync();
        echo sprintf(
            "BigBang catalog synchronized: %d created, %d updated, %d pages.\n",
            $result['created'],
            $result['updated'],
            $result['pages']
        );
    } elseif (($argv[1] ?? null) === '--launch-demo') {
        $catalog = $client->catalogPage(1, 1);
        $providerGameId = data_get($catalog, 'data.0.id');
        if (!ctype_digit((string) $providerGameId)) {
            throw new RuntimeException('BigBang catalog did not return a numeric game ID.');
        }

        $game = new Game(['provider_game_id' => (string) $providerGameId]);
        $callback = 'http://127.0.0.1/api/integrations/bigbang/user-data?token=connectivity-check';
        $payload = $client->launch($game, User::query()->firstOrFail(), $callback, 'http://127.0.0.1');
        echo 'BigBang demo launch passed: '.(isset($payload['launch_url']) ? 'launch URL received' : 'launch URL missing').PHP_EOL;
    } else {
        $payload = $client->catalogPage(1, 12);
        $games = $payload['games'] ?? $payload['data']['games'] ?? $payload['data'] ?? [];
        echo 'BigBang connectivity passed. Games received: '.(is_array($games) ? count($games) : 0).PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'BigBang connectivity blocked: '.$exception->getMessage().PHP_EOL);
    exit(2);
}
