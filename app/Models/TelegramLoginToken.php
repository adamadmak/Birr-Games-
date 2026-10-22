<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramLoginToken extends Model
{
    protected $fillable = [
        'user_id', 'telegram_subscriber_id', 'token_hash', 'destination', 'expires_at', 'used_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(TelegramSubscriber::class, 'telegram_subscriber_id');
    }
}
