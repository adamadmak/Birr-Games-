<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WalletTransaction extends Model
{
    protected $fillable = ['transaction_id', 'ledger_key', 'user_id', 'agent_id', 'payment_gateway_id', 'type', 'amount', 'fee', 'status', 'method', 'reason', 'currency', 'bank_code', 'source_type', 'source_id', 'metadata'];

    protected static function booted(): void
    {
        static::creating(fn (self $transaction) => $transaction->transaction_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'fee' => 'decimal:2', 'metadata' => 'array'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function gateway(): BelongsTo { return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id'); }
}
