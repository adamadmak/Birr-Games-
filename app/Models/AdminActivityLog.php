<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AdminActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'route_name',
        'method',
        'path',
        'target_type',
        'target_id',
        'response_status',
        'ip_address',
        'user_agent',
        'context',
    ];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Administrator activity logs are immutable.'));
        static::deleting(fn () => throw new LogicException('Administrator activity logs are immutable.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
