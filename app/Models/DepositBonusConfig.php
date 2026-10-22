<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepositBonusConfig extends Model
{
    protected $fillable = [
        'wagering_policy_id', 'validity_period', 'eligible_deposit_count', 'starts_at',
        'ends_at', 'bonus_type', 'reward_type', 'wallet_type', 'reward_value',
        'rollover_multiplier', 'maximum_payout', 'active',
    ];

    protected function casts(): array
    {
        return [
            'eligible_deposit_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reward_value' => 'decimal:2',
            'rollover_multiplier' => 'decimal:2',
            'maximum_payout' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(WageringPolicy::class, 'wagering_policy_id');
    }

    public function memberBonuses(): HasMany
    {
        return $this->hasMany(MemberBonus::class);
    }
}
