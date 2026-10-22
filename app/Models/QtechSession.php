<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QtechSession extends Model
{
    protected $fillable = [
        'user_id', 'external_session_id', 'currency', 'status', 'expires_at', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Live enough to authorise a bet.
     *
     * Only withdrawals require this. Deposits and rollbacks deliberately do
     * not: QT resolve unfinished rounds after a session has lapsed, and
     * refusing a payout because the player closed the game would strand money
     * the game has already awarded.
     */
    public function isLive(): bool
    {
        return $this->status === 'active'
            && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
