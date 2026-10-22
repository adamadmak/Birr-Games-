<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameProvider extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'accent', 'api_base_url', 'active', 'content_server_enabled', 'sync_enabled', 'last_synced_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'content_server_enabled' => 'boolean', 'sync_enabled' => 'boolean', 'last_synced_at' => 'datetime'];
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'provider_id');
    }
}
