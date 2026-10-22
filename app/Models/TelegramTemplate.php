<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramTemplate extends Model
{
    protected $fillable = ['name', 'caption', 'image_path', 'buttons'];

    protected function casts(): array
    {
        return ['buttons' => 'array'];
    }

    public function broadcasts(): HasMany
    {
        return $this->hasMany(TelegramBroadcast::class);
    }

    public function getImageSourceAttribute(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }
}
