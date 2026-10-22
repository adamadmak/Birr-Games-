<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameRound extends Model
{
    protected $fillable = [
        'user_id',
        'game',
        'provider',
        'external_round_id',
        'provider_session_id',
        'stake',
        'payout',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'stake' => 'decimal:2',
            'payout' => 'decimal:2',
            'result' => 'array',
        ];
    }

    /** House result for this round: positive when the player lost. */
    public function houseResult(): float
    {
        return (float) $this->stake - (float) $this->payout;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
