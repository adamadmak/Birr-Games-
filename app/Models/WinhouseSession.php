<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WinhouseSession extends Model
{
    protected $fillable = [
        'user_id', 'external_session_id', 'mode', 'currency',
        'status', 'expires_at', 'last_activity_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->status === 'active'
            && (!$this->expires_at || $this->expires_at->isFuture());
    }
}
