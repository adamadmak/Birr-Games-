<?php

use App\Http\Middleware\DeduplicateTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$requestFor = static function (int $updateId, string $message = 'hello'): Request {
    $payload = json_encode([
        'update_id' => $updateId,
        'message' => ['message_id' => 1, 'text' => $message],
    ], JSON_THROW_ON_ERROR);

    return Request::create(
        '/api/integrations/telegram/webhook',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $payload,
    );
};

DB::beginTransaction();
try {
    $middleware = $app->make(DeduplicateTelegramUpdate::class);
    $calls = 0;
    $next = function () use (&$calls): JsonResponse {
        $calls++;

        return response()->json(['ok' => true]);
    };
    $updateId = random_int(1_000_000_000, 2_000_000_000);

    $first = $middleware->handle($requestFor($updateId), $next);
    $duplicate = $middleware->handle($requestFor($updateId), $next);
    if ($first->getStatusCode() !== 200
        || $duplicate->getStatusCode() !== 200
        || $calls !== 1
        || TelegramUpdate::query()->where('update_id', $updateId)->value('status') !== 'completed') {
        throw new RuntimeException('A duplicate Telegram update was processed more than once.');
    }

    $conflict = $middleware->handle($requestFor($updateId, 'different payload'), $next);
    if ($conflict->getStatusCode() !== 409 || $calls !== 1) {
        throw new RuntimeException('A reused Telegram update ID with a conflicting payload was accepted.');
    }

    echo "Telegram update deduplication smoke tests passed.\n";
} finally {
    DB::rollBack();
}
