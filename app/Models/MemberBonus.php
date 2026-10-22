<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberBonus extends Model
{
    protected $fillable = [
        'user_id', 'deposit_bonus_config_id', 'bonus_claim_id', 'status',
        'amount_awarded', 'rollover_required', 'contributed', 'expires_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_awarded' => 'decimal:2',
            'rollover_required' => 'decimal:2',
            'contributed' => 'decimal:2',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected function progress(): Attribute
    {
        return Attribute::get(fn () => (float) $this->rollover_required > 0
            ? min(100, round(((float) $this->contributed / (float) $this->rollover_required) * 100, 1))
            : 100.0);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(DepositBonusConfig::class, 'deposit_bonus_config_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(BonusClaim::class, 'bonus_claim_id');
    }
}
