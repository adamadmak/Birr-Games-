<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShamoParticipation extends Model
{
    public const STATUS_JOINED = 'joined';
    public const STATUS_AWARDED = 'awarded';
    public const STATUS_CLAIMED = 'claimed';

    protected $fillable = [
        'shamo_campaign_id', 'user_id', 'position', 'prize_amount',
        'status', 'joined_at', 'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'prize_amount' => 'integer',
            'joined_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ShamoCampaign::class, 'shamo_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
