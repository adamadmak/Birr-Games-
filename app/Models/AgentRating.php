<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRating extends Model
{
    public const MIN_SCORE = 1;
    public const MAX_SCORE = 5;

    protected $fillable = ['agent_order_id', 'agent_id', 'user_id', 'score', 'comment'];

    protected function casts(): array
    {
        return ['score' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AgentOrder::class, 'agent_order_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
