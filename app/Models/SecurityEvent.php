<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One row per registration or sign-in attempt worth remembering.
 *
 * Allowed registrations are recorded too (they are what the per-network
 * duplicate check counts), but the point of the table is the refusals: what
 * was tried, from where, and why it was turned away, so an administrator can
 * see an attack as it happens instead of finding the accounts afterwards.
 */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_REGISTRATION = 'registration';
    public const TYPE_LOGIN = 'login';
    public const TYPE_VERIFICATION = 'verification';
    public const TYPE_PASSWORD_RESET = 'password_reset';

    public const OUTCOME_ALLOWED = 'allowed';
    public const OUTCOME_BLOCKED = 'blocked';
    public const OUTCOME_FAILED = 'failed';

    protected $fillable = [
        'type', 'outcome', 'reason', 'ip', 'user_agent', 'identifier', 'user_id', 'context',
    ];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an event, never letting the log be the reason a request fails.
     */
    public static function record(string $type, string $outcome, ?string $reason, ?string $ip, ?string $userAgent, ?string $identifier = null, ?int $userId = null, array $context = []): ?self
    {
        try {
            return static::create([
                'type' => $type,
                'outcome' => $outcome,
                'reason' => $reason !== null ? Str::limit($reason, 60, '') : null,
                'ip' => $ip !== null ? Str::limit($ip, 45, '') : null,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
                'identifier' => $identifier !== null && $identifier !== '' ? Str::limit($identifier, 190, '') : null,
                'user_id' => $userId,
                'context' => $context !== [] ? $context : null,
            ]);
        } catch (\Throwable) {
            // Table not migrated yet, or a database blip: the request goes on.
            return null;
        }
    }

    public function scopeSuspicious(Builder $query): Builder
    {
        return $query->whereIn('outcome', [self::OUTCOME_BLOCKED, self::OUTCOME_FAILED]);
    }

    public function scopeSince(Builder $query, \DateTimeInterface $since): Builder
    {
        return $query->where('created_at', '>=', $since);
    }
}
