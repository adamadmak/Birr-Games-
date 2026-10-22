<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PredictionPool extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_SETTLED = 'settled';

    protected $fillable = [
        'competition', 'home_team', 'away_team', 'home_crest_path', 'away_crest_path',
        'prize_pool', 'entry_cost', 'min_deposit',
        'kicks_off_at', 'closes_at', 'status', 'home_score', 'away_score', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'prize_pool' => 'integer',
            'entry_cost' => 'integer',
            'min_deposit' => 'integer',
            'home_score' => 'integer',
            'away_score' => 'integer',
            'kicks_off_at' => 'datetime',
            'closes_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PredictionEntry::class);
    }

    /**
     * The pool the lobby should show: published, still open for picks, and the
     * one closing soonest if an operator ever leaves two active.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('closes_at', '>', now())
            ->orderBy('closes_at');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->closes_at->isFuture();
    }

    public function isFree(): bool
    {
        return $this->entry_cost === 0;
    }

    public function crestUrl(string $side): ?string
    {
        $path = $side === 'home' ? $this->home_crest_path : $this->away_crest_path;

        if (! $path) {
            return null;
        }

        // Routed through the app rather than linked as /storage/..., which resolves
        // only through the public/storage symlink. That symlink is not in the
        // repository and no deploy step creates it, so linking that way served a 404
        // on the live host and every crest fell back to initials. A route needs no
        // filesystem setup and cannot be lost by a deploy.
        //
        // updated_at rides along so a replaced crest busts the year-long cache the
        // route sets; the file name itself is random per upload, but the URL is not.
        return route('predictions.crest', [
            'pool' => $this->getKey(),
            'side' => $side,
            'v' => optional($this->updated_at)->timestamp,
        ]);
    }

    /** Initials stand in for a crest that was never uploaded. */
    public function initials(string $side): string
    {
        $name = $side === 'home' ? $this->home_team : $this->away_team;
        $words = preg_split('/\s+/', trim($name)) ?: [];

        if (count($words) === 1) {
            return strtoupper(mb_substr($words[0], 0, 2));
        }

        return strtoupper(mb_substr($words[0] ?? '', 0, 1).mb_substr($words[1] ?? '', 0, 1));
    }
}
