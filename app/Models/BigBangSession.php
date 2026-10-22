<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BigBangSession extends Model
{
    protected $table = 'bigbang_sessions';

    protected $fillable = [
        'user_id', 'game_id', 'external_session_id', 'mode', 'currency',
        'status', 'expires_at', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_activity_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function game(): BelongsTo { return $this->belongsTo(Game::class); }
}
