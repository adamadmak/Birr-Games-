<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentOrder extends Model
{
    protected $fillable = ['user_id', 'agent_id', 'payment_gateway_id', 'type', 'amount', 'commission', 'reference', 'idempotency_key', 'account_name', 'account_number', 'status', 'auto_verified', 'processed_by_user_id', 'processed_at', 'reviewed_by_user_id', 'reviewed_at', 'payout_submitted_by_agent_id', 'payout_submitted_at', 'payout_reference', 'payout_proof_path', 'notes'];
    /**
     * Something the agent can check the transfer against: the player's bank
     * reference, or their payment screenshot. Either one is enough.
     */
    public function hasPaymentEvidence(): bool
    {
        if (filled($this->reference)) {
            return true;
        }

        return DepositProof::available()
            && DepositProof::query()->where('agent_order_id', $this->id)->exists();
    }

    protected function casts(): array { return ['amount' => 'decimal:2', 'commission' => 'decimal:2', 'auto_verified' => 'boolean', 'processed_at' => 'datetime', 'reviewed_at' => 'datetime', 'payout_submitted_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function gateway(): BelongsTo { return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id'); }
    public function processedBy(): BelongsTo { return $this->belongsTo(User::class, 'processed_by_user_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
    public function payoutSubmittedBy(): BelongsTo { return $this->belongsTo(Agent::class, 'payout_submitted_by_agent_id'); }
    public function events(): HasMany { return $this->hasMany(AgentOrderEvent::class)->latest('created_at'); }
    public function rating(): HasOne { return $this->hasOne(AgentRating::class); }
}
