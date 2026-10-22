<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = [
        'title',
        'description',
        'code',
        'accent',
        'promotion_type',
        'image_path',
        'active',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function getImageSourceAttribute(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }
}
