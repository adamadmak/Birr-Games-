<?php

namespace App\Jobs;

use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastDelivery;
use App\Services\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTelegramBroadcastEditBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public int $broadcastId, public bool $drain = false)
    {
        $this->onQueue('telegram');
    }

    public function handle(TelegramBotService $telegram): void
    {
        $broadcast = TelegramBroadcast::find($this->broadcastId);
        if (! $broadcast) {
            return;
        }

        $deliveries = TelegramBroadcastDelivery::query()
            ->where('telegram_broadcast_id', $broadcast->id)
            ->where('status', 'sent')
            ->whereNotNull('telegram_message_id')
            ->get();

        foreach ($deliveries as $delivery) {
            try {
                $telegram->editBroadcastMessage($broadcast, $delivery->chat_id, (int) $delivery->telegram_message_id);
            } catch (\Throwable) {
                // Ignore per-delivery edit error so remaining batch continues
            }
        }
    }
}
