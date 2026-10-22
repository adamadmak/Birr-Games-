<?php

namespace App\Http\Controllers;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\EmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController
{
    /**
     * The link from the email. Signed and time-limited by the route middleware;
     * this checks that the address it was sent to is still the one on the
     * account, then marks it verified. Nobody is signed in by clicking it —
     * a verification link in a forwarded email must not become a session.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if (! $user || ! filled($user->email) || ! hash_equals(EmailVerification::hashFor($user), $hash)) {
            SecurityEvent::record(SecurityEvent::TYPE_VERIFICATION, SecurityEvent::OUTCOME_FAILED, 'hash_mismatch', $request->ip(), $request->userAgent(), (string) $id);

            return redirect()->route('home')->with('error', 'That verification link is not valid. Request a new one from your profile.');
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
            SecurityEvent::record(SecurityEvent::TYPE_VERIFICATION, SecurityEvent::OUTCOME_ALLOWED, 'verified', $request->ip(), $request->userAgent(), $user->email, $user->id);
        }

        $message = 'Your email address is confirmed. Thank you!';

        if ($request->user()?->is($user)) {
            return redirect()->route('home')->with('status', $message);
        }

        return redirect()->route('login')->with('status', $message.' Sign in to continue.');
    }

    /** Resend the message to the signed-in player. Rate limited on the route. */
    public function resend(Request $request, EmailVerification $verification): RedirectResponse
    {
        $user = $request->user();

        if (! filled($user->email)) {
            return back()->with('error', 'There is no email address on your account.');
        }

        if ($user->email_verified_at !== null) {
            return back()->with('status', 'Your email address is already confirmed.');
        }

        return $verification->send($user)
            ? back()->with('status', 'We sent a new confirmation link to '.$user->email.'.')
            : back()->with('error', 'The confirmation email could not be sent right now. Please try again later.');
    }
}
