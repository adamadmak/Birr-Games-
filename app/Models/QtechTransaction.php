<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QtechTransaction extends Model
{
    public const TYPE_DEBIT = 'DEBIT';
    public const TYPE_CREDIT = 'CREDIT';
    public const TYPE_ROLLBACK = 'ROLLBACK';
    public const TYPE_REWARD = 'REWARD';

    protected $fillable = [
        'txn_id', 'type', 'user_id', 'round_id', 'bet_id', 'amount',
        'currency', 'response_balance', 'rolled_back', 'payload_hash', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'response_balance' => 'decimal:2',
            'rolled_back' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What QT stores as our side's identifier for this wallet movement. */
    public function referenceId(): string
    {
        return (string) $this->getKey();
    }
}
