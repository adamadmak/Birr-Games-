<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ending every browser a player is signed in on.
 *
 * A password someone else may have seen — one reissued by the bot, or one
 * replaced through a recovery link — is reason to sign out every browser
 * that knew the old one. Only the database session driver can be searched
 * by user; with the file driver the rotated remember token and the changed
 * password hash end the other sessions at their next check.
 */
final class PlayerSessions
{
    public static function endAll(User $user): void
    {
        try {
            $table = (string) config('session.table', 'sessions');

            if (config('session.driver') === 'database' && Schema::hasTable($table)) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
