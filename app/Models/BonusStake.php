<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bonus-funded share of a round that is still out. See App\Services\BonusPlay. */
class BonusStake extends Model
{
    protected $fillable = ['user_id', 'source', 'round_ref', 'stake_total', 'bonus_staked', 'paid_total', 'bonus_returned'];

    protected function casts(): array
    {
        return [
            'stake_total' => 'decimal:2',
            'bonus_staked' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'bonus_returned' => 'decimal:2',
        ];
    }
}
