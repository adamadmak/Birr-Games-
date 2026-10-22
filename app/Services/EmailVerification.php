<?php

namespace App\Services;

use App\Mail\VerifyEmailAddress;
use App\Models\User;
use App\Support\Mailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Sends the "confirm your email" message and knows what the link looks like.
 *
 * The link is a signed, time-limited URL carrying the user id and a hash of
 * the address it was sent to, so a link cannot be guessed, re-used after the
 * address changes, or kept for later. Sending is synchronous and never
 * throws: a mail server that is down must not undo a registration that has
 * already happened.
 */
final class EmailVerification
{
    public const LINK_LIFETIME_HOURS = 24;

    public static function hashFor(User $user): string
    {
        return sha1(strtolower(trim((string) $user->email)));
    }

    public static function url(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(self::LINK_LIFETIME_HOURS),
            ['id' => $user->getKey(), 'hash' => self::hashFor($user)]
        );
    }

    /** @return bool whether a message was handed to the mail server */
    public function send(User $user): bool
    {
        if (! filled($user->email) || $user->email_verified_at !== null || ! Mailer::configured()) {
            return false;
        }

        try {
            Mailer::prepare();
            Mail::to($user->email)->send(new VerifyEmailAddress($user, self::url($user)));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Verification email could not be sent.', [
                'user_id' => $user->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
