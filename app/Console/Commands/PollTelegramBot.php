<?php

namespace App\Console\Commands;

use App\Http\Controllers\TelegramWebhookController;
use App\Services\TelegramBotService;
use App\Services\TelegramPlayerService;
use App\Services\TelegramDepositService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PollTelegramBot extends Command
{
    protected $signature = 'telegram:poll {--timeout=10 : Telegram long-poll timeout in seconds} {--once : Process one batch and exit}';

    protected $description = 'Process Telegram bot updates when a public HTTPS webhook is unavailable';

    public function handle(
        TelegramBotService $telegram,
        TelegramPlayerService $players,
        TelegramDepositService $deposits,
    ): int
    {
        if (!$telegram->configured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');
            return self::FAILURE;
        }

        $webhook = $telegram->getWebhookInfo();
        if (filled($webhook['url'] ?? null)) {
            $this->error('A Telegram webhook is already active. Polling was not started.');
            return self::FAILURE;
        }

        $offset = (int) Cache::get('telegram.poll.offset', 0);
        $timeout = max(0, min(15, (int) $this->option('timeout')));
        $this->info('Telegram polling started. Press Ctrl+C to stop.');

        do {
            $updates = $telegram->getUpdates($offset, $timeout);

            foreach ($updates as $update) {
                $updateId = (int) data_get($update, 'update_id');
                $request = Request::create('/api/integrations/telegram/webhook', 'POST', $update);
                $request->headers->set('X-Telegram-Bot-Api-Secret-Token', $telegram->webhookSecret());

                $response = app(TelegramWebhookController::class)($request, $telegram, $players, $deposits);
                if ($response->getStatusCode() >= 500) {
                    $this->error('Telegram update '.$updateId.' failed and was left available for retry.');
                    continue;
                }

                $offset = max($offset, $updateId + 1);
                Cache::forever('telegram.poll.offset', $offset);
                $this->line('Processed Telegram update '.$updateId.'.');
            }
        } while (!$this->option('once'));

        return self::SUCCESS;
    }
}
