<?php

namespace App\Support;

use App\Models\User;

/**
 * Players are identified by a handle plus their phone number — never by a synthetic
 * email address. The handle comes from their Telegram username when we have one, and
 * otherwise from the name they registered with.
 */
final class Username
{
    private const MAX_LENGTH = 32;
    private const MIN_LENGTH = 3;

    /** Reduce any input to a safe handle: lowercase letters, digits and underscores. */
    public static function sanitize(?string $value): string
    {
        $handle = strtolower(trim((string) $value));
        $handle = str_replace(['@', ' ', '-', '.', '+'], '_', $handle);
        $handle = preg_replace('/[^a-z0-9_]/', '', $handle) ?? '';
        $handle = preg_replace('/_+/', '_', $handle) ?? '';
        $handle = trim($handle, '_');

        return mb_substr($handle, 0, self::MAX_LENGTH);
    }

    /**
     * Build a handle that is not taken yet. Candidates are tried in order, then a numeric
     * suffix is added until the handle is free.
     *
     * @param  array<int, string|null>  $candidates
     */
    public static function unique(array $candidates, ?string $phone = null, ?int $ignoreUserId = null): string
    {
        $base = '';
        foreach ($candidates as $candidate) {
            $base = self::sanitize($candidate);
            if (mb_strlen($base) >= self::MIN_LENGTH) {
                break;
            }
        }

        if (mb_strlen($base) < self::MIN_LENGTH) {
            $tail = substr(preg_replace('/\D+/', '', (string) $phone) ?: '', -4);
            $base = self::sanitize('player'.$tail);
        }

        $handle = $base;
        $suffix = 1;
        while (self::taken($handle, $ignoreUserId)) {
            $suffix++;
            $handle = mb_substr($base, 0, self::MAX_LENGTH - strlen((string) $suffix) - 1).'_'.$suffix;
        }

        return $handle;
    }

    /** Archived accounts still occupy their handle, so they count as taken. */
    private static function taken(string $handle, ?int $ignoreUserId): bool
    {
        return User::withTrashed()
            ->where('username', $handle)
            ->when($ignoreUserId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }
}
