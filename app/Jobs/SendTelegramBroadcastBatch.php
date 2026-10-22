<?php

namespace App\Jobs;

use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastDelivery;
use App\Services\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTelegramBroadcastBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 300;
    public array $backoff = [5, 30, 120];

    public function __construct(public int $broadcastId, public bool $drain = false)
    {
        $this->onQueue('telegram');
    }

    public function handle(TelegramBotService $telegram): void
    {
        $broadcast = TelegramBroadcast::find($this->broadcastId);

        if (!$broadcast || !in_array($broadcast->status, ['queued', 'scheduled', 'sending'], true)) {
            return;
        }

        if ($broadcast->scheduled_at?->isFuture()) {
            self::dispatch($broadcast->id)->delay($broadcast->scheduled_at);
            return;
        }

        $broadcast->update([
            'status' => 'sending',
            'started_at' => $broadcast->started_at ?? now(),
        ]);
        TelegramBroadcastDelivery::query()
            ->where('telegram_broadcast_id', $broadcast->id)
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'pending', 'updated_at' => now()]);

        do {
            $deliveryIds = TelegramBroadcastDelivery::query()
                ->where('telegram_broadcast_id', $broadcast->id)
                ->where('status', 'pending')
                ->orderBy('id')
                ->limit(20)
                ->pluck('id');

            foreach ($deliveryIds as $deliveryId) {
                $claimed = TelegramBroadcastDelivery::query()
                    ->whereKey($deliveryId)
                    ->where('status', 'pending')
                    ->update(['status' => 'processing', 'updated_at' => now()]);
                if ($claimed !== 1) {
                    continue;
                }
                $delivery = TelegramBroadcastDelivery::findOrFail($deliveryId);
                try {
                    $result = $telegram->sendBroadcast($broadcast, $delivery->chat_id);
                    $delivery->update([
                        'status' => 'sent',
                        'telegram_message_id' => $result['message_id'] ?? null,
                        'error' => null,
                        'sent_at' => now(),
                    ]);
                } catch (\Throwable $exception) {
                    $delivery->update([
                        'status' => 'failed',
                        'error' => str($exception->getMessage())->limit(500)->toString(),
                    ]);
                    $message = strtolower($exception->getMessage());
                    if (str_contains($message, 'blocked by the user') || str_contains($message, 'chat not found') || str_contains($message, 'user is deactivated')) {
                        $delivery->subscriber?->update(['active' => false, 'stopped_at' => now()]);
                    }
                }
            }

            $remaining = TelegramBroadcastDelivery::query()
                ->where('telegram_broadcast_id', $broadcast->id)
                ->where('status', 'pending')
                ->exists();
        } while ($this->drain && $remaining && $deliveryIds->isNotEmpty());

        $sent = $broadcast->deliveries()->where('status', 'sent')->count();
        $failed = $broadcast->deliveries()->where('status', 'failed')->count();
        $pending = $broadcast->deliveries()->whereIn('status', ['pending', 'processing'])->exists();
        $broadcast->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'status' => $pending ? 'sending' : ($sent === 0 && $failed > 0 ? 'failed' : 'completed'),
            'completed_at' => $pending ? null : now(),
        ]);

        if ($pending && !$this->drain) {
            self::dispatch($broadcast->id)->delay(now()->addSecond());
        }
    }
}
