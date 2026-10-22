<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Schema;

final class Money
{
    private static ?string $currencyCode = null;

    public static function code(): string
    {
        if (self::$currencyCode !== null) {
            return self::$currencyCode;
        }

        $code = 'ETB';

        if (Schema::hasTable('platform_settings')) {
            $code = (string) PlatformSetting::valueFor('currency_code', $code);
        }

        return self::$currencyCode = strtoupper($code ?: 'ETB');
    }

    public static function format(int|float|string|null $amount, int $decimals = 0): string
    {
        return self::code().' '.number_format((float) ($amount ?? 0), $decimals);
    }

    /**
     * A figure a player is expected to act on: whole where it is whole, to the
     * cent where it is not.
     *
     * Balances are decimal:2, so a limit can carry a fraction, and rounding one
     * to the nearest birr is how a page comes to state a ceiling the field will
     * not accept — 23,000.75 shown as 23,001, refused on entry.
     */
    public static function exact(int|float|string|null $amount): string
    {
        return self::code().' '.self::amount($amount);
    }

    /** The same figure without the currency code, for markup that prints its own. */
    public static function amount(int|float|string|null $amount): string
    {
        $value = (float) ($amount ?? 0);

        return number_format($value, abs($value - round($value)) < 0.001 ? 0 : 2);
    }
}
