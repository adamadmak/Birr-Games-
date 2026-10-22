<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WinhouseTransaction extends Model
{
    protected $fillable = [
        'provider_transaction_id', 'action', 'user_id', 'ticket_id',
        'provider_session_id', 'amount', 'currency', 'response_balance',
        'payload_hash', 'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'response_balance' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
