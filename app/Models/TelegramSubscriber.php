<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramSubscriber extends Model
{
    protected $fillable = [
        'user_id', 'telegram_user_id', 'chat_id', 'username', 'first_name', 'last_name',
        'phone', 'phone_shared_at', 'language_code', 'chat_type', 'active', 'pending_action',
        'pending_payload', 'started_at', 'stopped_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'pending_payload' => 'array',
            'phone_shared_at' => 'datetime',
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** A verified contact card is required before any player account is created or opened. */
    public function hasSharedPhone(): bool
    {
        return filled($this->phone);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loginTokens(): HasMany
    {
        return $this->hasMany(TelegramLoginToken::class);
    }
}
