<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentOrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_order_id', 'actor_type', 'actor_id', 'actor_name', 'event',
        'from_status', 'to_status', 'notes', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AgentOrder::class, 'agent_order_id');
    }

    public static function record(
        AgentOrder $order,
        string $event,
        mixed $actor = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $notes = null,
        array $metadata = [],
    ): self {
        $actorType = $actor instanceof User ? ($actor->isAdmin() ? 'administrator' : 'player')
            : ($actor instanceof Agent ? 'agent' : 'system');

        return self::create([
            'agent_order_id' => $order->id,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name ?? $actor?->nickname ?? 'System',
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'metadata' => $metadata ?: null,
        ]);
    }
}
