<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected static array $valueCache = [];

    public $timestamps = false;

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected static bool $allLoaded = false;

    /**
     * Settings are read a handful of times per page — limits, prizes, welcome balance — and
     * each miss used to be its own query. The table is tiny, so the first read pulls the lot
     * and every later read is served from memory.
     */
    public static function valueFor(string $key, mixed $default = null): mixed
    {
        if (! static::$allLoaded && ! array_key_exists($key, static::$valueCache)) {
            static::warm();
        }

        return static::$valueCache[$key] ?? $default;
    }

    /** Load every setting in one query. */
    public static function warm(): void
    {
        try {
            static::$valueCache = static::query()->pluck('value', 'key')->all() + static::$valueCache;
            static::$allLoaded = true;
        } catch (\Throwable) {
            // A missing table during install or migration must not break page rendering.
            static::$allLoaded = false;
        }
    }

    public static function telegramSupportUrl(): string
    {
        $val = trim((string) static::valueFor('telegram_support_username', 'FaithAdam'));
        if ($val === '') {
            $val = 'FaithAdam';
        }
        if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) {
            return $val;
        }

        return 'https://t.me/'.ltrim($val, '@');
    }

    public static function flushValueCache(): void
    {
        static::$valueCache = [];
        static::$allLoaded = false;
    }
}
