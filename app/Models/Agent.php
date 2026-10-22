<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Authenticatable
{
    protected $fillable = ['first_name', 'last_name', 'nickname', 'phone', 'telegram_chat_id', 'telegram_link_code', 'telegram_linked_at', 'password', 'balance', 'commission_enabled', 'commission_rate', 'active'];
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'balance' => 'decimal:2', 'commission_enabled' => 'boolean', 'commission_rate' => 'decimal:2', 'telegram_linked_at' => 'datetime', 'active' => 'boolean'];
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(AgentRating::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(AgentBankAccount::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(AgentOrder::class);
    }
}
