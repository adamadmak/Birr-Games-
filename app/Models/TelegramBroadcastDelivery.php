<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramBroadcastDelivery extends Model
{
    protected $fillable = [
        'telegram_broadcast_id', 'telegram_subscriber_id', 'chat_id', 'status',
        'telegram_message_id', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(TelegramBroadcast::class, 'telegram_broadcast_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(TelegramSubscriber::class, 'telegram_subscriber_id');
    }
}
