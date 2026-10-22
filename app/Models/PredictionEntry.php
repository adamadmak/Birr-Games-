<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionEntry extends Model
{
    protected $fillable = ['prediction_pool_id', 'user_id', 'home_score', 'away_score', 'is_winner'];

    protected function casts(): array
    {
        return [
            'home_score' => 'integer',
            'away_score' => 'integer',
            'is_winner' => 'boolean',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(PredictionPool::class, 'prediction_pool_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scoreline(): string
    {
        return $this->home_score.' – '.$this->away_score;
    }
}
