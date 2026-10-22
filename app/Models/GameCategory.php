<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'sort_order', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'category_id');
    }
}
