<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BonusClaim extends Model
{
    public const FIRST_DEMO_TOP_UP = 'first_demo_top_up';

    protected $fillable = ['user_id', 'bonus_type', 'credits', 'claimed_at'];

    protected function casts(): array
    {
        return ['credits' => 'integer', 'claimed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function memberBonus(): HasOne
    {
        return $this->hasOne(MemberBonus::class);
    }
}
