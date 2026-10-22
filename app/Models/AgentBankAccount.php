<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentBankAccount extends Model
{
    protected $fillable = ['agent_id', 'payment_gateway_id', 'account_name', 'account_number', 'notes', 'active'];
    protected function casts(): array { return ['active' => 'boolean']; }
    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
    public function gateway(): BelongsTo { return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id'); }
}
