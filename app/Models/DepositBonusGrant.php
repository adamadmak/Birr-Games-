<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deposit bonus an approved deposit earned.
 *
 * The bonus is play-only credit: it raises `users.credits` but never
 * `users.withdrawable_balance`, so a player can stake it and keep what it wins
 * but cannot withdraw the bonus itself. See App\Services\DepositBonusService.
 */
class DepositBonusGrant extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FORFEITED = 'forfeited';

    protected $fillable = [
        'user_id', 'agent_order_id', 'deposit_amount', 'percent', 'amount', 'forfeited',
        'status', 'forfeit_reason', 'expires_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'deposit_amount' => 'decimal:2',
            'percent' => 'decimal:2',
            'amount' => 'decimal:2',
            'forfeited' => 'decimal:2',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function remaining(): float
    {
        return max(0.0, round((float) $this->amount - (float) $this->forfeited, 2));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AgentOrder::class, 'agent_order_id');
    }
}
