<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUpdate extends Model
{
    protected $fillable = ['update_id', 'payload_hash', 'status', 'error', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
