<?php

namespace App\Support;

/**
 * Normalises Ethiopian mobile numbers so the same person is always resolved to
 * one identity no matter whether they signed up on the web form (0912…),
 * were imported from a CSV (251912…) or shared their contact card in
 * Telegram (+251912…).
 */
final class PhoneNumber
{
    private const COUNTRY_CODE = '251';

    /** Local mobile prefixes issued in Ethiopia (9 = mobile, 7 = mobile, 1..5 = landline area codes). */
    private const LOCAL_LENGTH = 9;

    /**
     * Reduce any input to bare digits in international form, e.g. "251912345678".
     * Returns null when the value cannot be a phone number.
     */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // International trunk prefix: 00251912345678 -> 251912345678
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // National trunk prefix: 0912345678 -> 251912345678
        if (strlen($digits) === self::LOCAL_LENGTH + 1 && str_starts_with($digits, '0')) {
            $digits = self::COUNTRY_CODE.substr($digits, 1);
        } elseif (strlen($digits) === self::LOCAL_LENGTH && ! str_starts_with($digits, '0')) {
            // Bare subscriber number: 912345678 -> 251912345678
            $digits = self::COUNTRY_CODE.$digits;
        }

        return strlen($digits) >= self::LOCAL_LENGTH ? $digits : null;
    }

    /**
     * Every stored spelling of a number that should be treated as the same person.
     * Used for `whereIn('phone', …)` lookups against historic rows.
     *
     * @return array<int, string>
     */
    public static function candidates(?string $value): array
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return [];
        }

        $variants = [$normalized, '+'.$normalized];

        if (str_starts_with($normalized, self::COUNTRY_CODE)) {
            $local = substr($normalized, strlen(self::COUNTRY_CODE));
            $variants[] = $local;
            $variants[] = '0'.$local;
            $variants[] = '+'.self::COUNTRY_CODE.' '.$local;
            $variants[] = self::COUNTRY_CODE.' '.$local;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /** True when the value looks like a usable Ethiopian mobile number. */
    public static function valid(?string $value): bool
    {
        $normalized = self::normalize($value);

        return $normalized !== null && strlen($normalized) >= 11 && strlen($normalized) <= 15;
    }

    /** Display helper: 251912345678 -> +251 912 345 678 */
    public static function format(?string $value): string
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return (string) $value;
        }

        if (! str_starts_with($normalized, self::COUNTRY_CODE)) {
            return '+'.$normalized;
        }

        $local = substr($normalized, strlen(self::COUNTRY_CODE));

        return trim('+'.self::COUNTRY_CODE.' '.implode(' ', str_split($local, 3)));
    }
}
