<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BigBangTransaction extends Model
{
    protected $table = 'bigbang_transactions';

    protected $fillable = [
        'provider_transaction_id', 'action', 'user_id', 'game_id', 'game_round_id',
        'provider_session_id', 'original_provider_transaction_id', 'amount', 'currency', 'response_balance', 'payload_hash', 'metadata',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'response_balance' => 'decimal:2', 'metadata' => 'array'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function game(): BelongsTo { return $this->belongsTo(Game::class); }
    public function round(): BelongsTo { return $this->belongsTo(GameRound::class, 'game_round_id'); }
}
