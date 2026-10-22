<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramBroadcast extends Model
{
    protected $fillable = [
        'telegram_template_id', 'created_by_user_id', 'name', 'caption', 'image_path', 'buttons',
        'status', 'total_recipients', 'sent_count', 'failed_count', 'scheduled_at', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'buttons' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TelegramTemplate::class, 'telegram_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(TelegramBroadcastDelivery::class);
    }

    public function getProgressAttribute(): int
    {
        if ($this->total_recipients === 0) {
            return 0;
        }

        return min(100, (int) round((($this->sent_count + $this->failed_count) / $this->total_recipients) * 100));
    }

    public function getImageSourceAttribute(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }
}
