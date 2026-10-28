<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordLink;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Mailer;
use App\Support\PlayerSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Getting back into an account whose password is lost.
 *
 * Players sign up with a phone number, and until 28 Oct 2026 a forgotten
 * password meant a wallet with money in it was locked for good. Two ways
 * back, both of which prove the person controls something the account was
 * registered with:
 *
 *  - Telegram, for everyone: the bot's /password command issues a new
 *    password, but only to a chat whose Telegram contact card carries the
 *    account's phone number (TelegramWebhookController). The page here just
 *    sends the player to the bot with a `start=password` deep link.
 *  - Email, for accounts with a confirmed address: a reset link through
 *    Laravel's password broker (hashed token, one hour), sent only to the
 *    address already on the account.
 *
 * The request form answers the same way whether or not the account exists,
 * so it cannot be used to find out which phone numbers are registered. Every
 * outcome lands in security_events (Configuration → Security).
 */
class PasswordRecoveryController
{
    public function show(): View
    {
        return view('auth.forgot', $this->pageData());
    }

    /** Send the reset link when the account can receive one; say the same thing either way. */
    public function email(Request $request): RedirectResponse
    {
        $request->validate(['account' => ['required', 'string', 'max:190']]);

        $login = trim((string) $request->input('account'));
        $user = User::findByLogin($login);
        $reason = match (true) {
            $user === null => 'unknown_account',
            ! $user->active => 'inactive_account',
            ! filled($user->email) || $user->email_verified_at === null => 'no_confirmed_email',
            ! Mailer::configured() => 'mail_not_configured',
            default => 'link_sent',
        };

        if ($reason === 'link_sent') {
            $sent = $this->sendLink($user);
            $reason = $sent ? 'link_sent' : 'mail_failed';
        }

        SecurityEvent::record(
            SecurityEvent::TYPE_PASSWORD_RESET,
            $reason === 'link_sent' ? SecurityEvent::OUTCOME_ALLOWED : SecurityEvent::OUTCOME_FAILED,
            $reason,
            $request->ip(),
            $request->userAgent(),
            $login,
            $user?->id
        );

        return redirect()->route('password.request')->with('recovery_sent', true);
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'email:rfc', 'max:160'],
            'password' => ['required', 'confirmed', 'max:1024', PasswordRule::min(8)],
        ]);

        $email = strtolower(trim((string) $request->input('email')));
        $status = Password::broker()->reset(
            [
                'email' => $email,
                'token' => (string) $request->input('token'),
                'password' => (string) $request->input('password'),
                'password_confirmation' => (string) $request->input('password_confirmation'),
            ],
            function (User $user, string $password): void {
                // A new remember token signs out every "remember me" browser too.
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                PlayerSessions::endAll($user);
                Log::info('A player password was reset through an email link.', ['user_id' => $user->id]);
            }
        );

        $ok = $status === Password::PASSWORD_RESET;

        SecurityEvent::record(
            SecurityEvent::TYPE_PASSWORD_RESET,
            $ok ? SecurityEvent::OUTCOME_ALLOWED : SecurityEvent::OUTCOME_FAILED,
            $ok ? 'password_changed' : 'bad_token',
            $request->ip(),
            $request->userAgent(),
            $email
        );

        if (! $ok) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => __('This link is no longer valid. Ask for a new one.'),
            ]);
        }

        return redirect()->route('login')->with('status', __('Your password has been changed. Sign in with the new one.'));
    }

    private function sendLink(User $user): bool
    {
        try {
            $token = Password::broker()->createToken($user);
            // The public address, not the Host header of whoever asked: a reset link
            // must never point anywhere but the site.
            $url = \App\Support\SiteUrl::route('password.reset', ['token' => $token, 'email' => $user->email]);

            Mailer::prepare();
            Mail::to($user->email)->send(new ResetPasswordLink($user, $url));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Password reset email could not be sent.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function pageData(): array
    {
        $bot = ltrim(app(\App\Services\TelegramBotService::class)->botUsername(), '@');

        return [
            'botUrl' => $bot !== '' ? 'https://t.me/'.$bot.'?start=password' : '',
            'supportUrl' => \App\Models\PlatformSetting::telegramSupportUrl(),
            'mailReady' => Mailer::configured(),
        ];
    }
}
