<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentGateway extends Model
{
    protected $fillable = ['name', 'code', 'type', 'account_label', 'supports_deposit', 'supports_withdrawal', 'active', 'configuration'];

    protected function casts(): array
    {
        return ['supports_deposit' => 'boolean', 'supports_withdrawal' => 'boolean', 'active' => 'boolean', 'configuration' => 'array'];
    }

    public function agentAccounts(): HasMany
    {
        return $this->hasMany(AgentBankAccount::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        $key = strtolower(trim($this->code ?: $this->name));
        $cleanKey = preg_replace('/[^a-z0-9]/', '', $key);

        if (str_contains($cleanKey, 'cbe')) {
            return asset('images/cbebirr.png');
        }
        if (str_contains($cleanKey, 'tele')) {
            return asset('images/Telebirr.jpg');
        }
        if (str_contains($cleanKey, 'ebirr')) {
            return asset('images/Ebirr.png');
        }

        foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $ext) {
            if (file_exists(public_path("images/{$this->name}.{$ext}"))) {
                return asset("images/{$this->name}.{$ext}");
            }
            if (file_exists(public_path("images/{$cleanKey}.{$ext}"))) {
                return asset("images/{$cleanKey}.{$ext}");
            }
        }

        return null;
    }
}
