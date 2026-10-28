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

    /**
     * Artwork that cannot be found by name (ported from kulubet). The file is
     * named for the brand and the gateway for the institution, and the two
     * rarely match: M-PESA ships as MPSA.png, Bank of Abyssinia as BOA.png.
     *
     * ORDER MATTERS: the first hit wins. "cbebirr" contains "ebirr", so `cbe`
     * is tested before `ebirr`, or every CBE gateway would show the Ebirr mark.
     */
    private const LOGO_FILES = [
        'cbe' => 'cbebirr.png',
        'tele' => 'Telebirr.jpg',
        'ebirr' => 'Ebirr.png',
        'abyssinia' => 'BOA.png',
        'boa' => 'BOA.png',
        'mpesa' => 'MPSA.png',
        'mpsa' => 'MPSA.png',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        $key = strtolower(trim($this->code ?: $this->name));
        $cleanKey = preg_replace('/[^a-z0-9]/', '', $key);

        foreach (self::LOGO_FILES as $needle => $file) {
            if (str_contains($cleanKey, $needle)) {
                return asset('images/'.$file);
            }
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
