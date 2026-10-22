<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WageringPolicy extends Model
{
    protected $fillable = [
        'name', 'minimum_stake', 'minimum_deposit', 'maximum_contribution',
        'contribution_type', 'contribution_percentage',
    ];

    protected function casts(): array
    {
        return [
            'minimum_stake' => 'decimal:2',
            'minimum_deposit' => 'decimal:2',
            'maximum_contribution' => 'decimal:2',
            'contribution_percentage' => 'decimal:2',
        ];
    }

    public function depositBonusConfigs(): HasMany
    {
        return $this->hasMany(DepositBonusConfig::class);
    }
}
