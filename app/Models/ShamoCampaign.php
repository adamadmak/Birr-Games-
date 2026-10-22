<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShamoCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = ['name', 'description', 'entry_cost', 'reward_pool', 'starts_at', 'ends_at', 'status'];

    protected function casts(): array
    {
        return [
            'entry_cost' => 'integer',
            'reward_pool' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ShamoParticipation::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && (!$this->starts_at || $this->starts_at->isPast())
            && (!$this->ends_at || $this->ends_at->isFuture());
    }
}
