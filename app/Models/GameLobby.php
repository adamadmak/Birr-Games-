<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GameLobby extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'sort_order', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'game_lobby_game', 'lobby_id', 'game_id')->withPivot('sort_order');
    }
}
