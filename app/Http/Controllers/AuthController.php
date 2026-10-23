<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Repositories\UserRepository;
use App\Services\EmailVerification;
use App\Services\RegistrationGuard;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController
{
    public function showLogin(): View
    {
        return view('auth.login', [
            'turnstileSiteKey' => Turnstile::protectsLogin() ? Turnstile::siteKey() : '',
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $loginInput = trim((string) $request->input('phone', $request->input('email', $request->input('login'))));

        $request->validate([
            'password' => ['required', 'string', 'max:1024'],
        ]);

        if ($loginInput === '' || mb_strlen($loginInput) > 190) {
            return back()->withErrors(['phone' => 'Please enter your phone number or username.']);
        }

        if (Turnstile::protectsLogin()) {
            $check = Turnstile::verify($request->input('cf-turnstile-response'), $request->ip(), 'login');

            if (! $check['ok']) {
                SecurityEvent::record(SecurityEvent::TYPE_LOGIN, SecurityEvent::OUTCOME_BLOCKED, 'turnstile:'.$check['reason'], $request->ip(), $request->userAgent(), $loginInput);

                return back()->withInput($request->only('phone', 'email'))->withErrors([
                    'phone' => $check['reason'] === 'unreachable'
                        ? 'The verification service is not reachable right now. Please try again in a minute.'
                        : 'Please complete the "I am human" check and try again.',
                ]);
            }
        }

        // Numbers are stored in several spellings (0912…, 251912…, +251 912…), so match
        // every variant of the same phone number rather than the literal input only.
        // Players sign in with a phone number or handle; administrators with their email.
        $phoneCandidates = array_values(array_unique(array_merge([$loginInput], PhoneNumber::candidates($loginInput))));

        $user = User::query()
            ->where(fn ($query) => $query
                ->whereIn('phone', $phoneCandidates)
                ->orWhere('username', ltrim($loginInput, '@'))
                ->orWhere('email', $loginInput))
            ->orderBy('id')
            ->first();

        if (! $user || ! Auth::attempt(['id' => $user->id, 'password' => $request->input('password'), 'active' => true], $request->boolean('remember'))) {
            // Same message whether the account exists or not: a sign-in form must
            // not confirm which phone numbers are registered.
            SecurityEvent::record(SecurityEvent::TYPE_LOGIN, SecurityEvent::OUTCOME_FAILED, $user ? 'bad_password' : 'unknown_account', $request->ip(), $request->userAgent(), $loginInput, $user?->id);

            return back()->withInput($request->only('phone', 'email'))->withErrors([
                'phone' => 'Those credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function showRegister(Request $request, RegistrationGuard $guard): View
    {
        return view('auth.register', [
            'welcomeBalance' => (int) PlatformSetting::valueFor('welcome_credits', 5),
            'registrationToken' => $guard->issueToken($request),
            'turnstileSiteKey' => Turnstile::configured() ? Turnstile::siteKey() : '',
            'emailMode' => RegistrationGuard::emailMode(),
        ]);
    }

    public function register(Request $request, RegistrationGuard $guard, EmailVerification $verification): RedirectResponse
    {
        // Bot and abuse checks first, before any of the input is trusted.
        $guard->inspect($request);

        $collectsEmail = RegistrationGuard::collectsEmail();
        $requiresEmail = RegistrationGuard::requiresEmail();

        // Everything is validated here regardless of what the browser enforced:
        // the length caps, the character sets for names and handles, and the
        // password policy are the server's rules, not the form's.
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:60', 'regex:/^[\p{L}\p{M}][\p{L}\p{M}\s\'\-\.]*$/u'],
            'last_name' => ['required', 'string', 'max:60', 'regex:/^[\p{L}\p{M}][\p{L}\p{M}\s\'\-\.]*$/u'],
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[A-Za-z0-9_]+$/', 'unique:users,username'],
            // Uniqueness is enforced below against every spelling of the number, including
            // archived accounts, which a plain unique rule cannot express.
            'phone' => ['required', 'string', 'max:30', 'regex:/^[+0-9\s\-()]+$/'],
            'email' => [
                $requiresEmail ? 'required' : 'nullable',
                'string', 'email:rfc', 'max:160',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'password' => ['required', 'confirmed', 'max:1024', Password::min(8)],
            'referral_code' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ], [
            'first_name.regex' => 'Your first name may only contain letters.',
            'last_name.regex' => 'Your last name may only contain letters.',
            'username.regex' => 'A username may only contain letters, numbers and underscores.',
            'phone.regex' => 'Enter a valid phone number, for example 0912345678.',
            'referral_code.regex' => 'That referral code is not valid.',
            'email.unique' => 'An account already uses this email address. Please log in instead.',
        ]);

        $email = $collectsEmail && filled($validated['email'] ?? null) ? strtolower(trim($validated['email'])) : null;

        if ($email !== null && RegistrationGuard::isDisposableEmail($email)) {
            SecurityEvent::record(SecurityEvent::TYPE_REGISTRATION, SecurityEvent::OUTCOME_BLOCKED, 'disposable_email', $request->ip(), $request->userAgent(), $email);

            throw ValidationException::withMessages(['email' => 'Temporary email addresses cannot be used. Please enter an address you can keep.']);
        }

        // Store one canonical spelling so the same person cannot end up with a second
        // account through Telegram (or a differently formatted number) later on.
        $normalizedPhone = PhoneNumber::normalize($validated['phone']);
        if ($normalizedPhone === null) {
            return back()->withInput($request->except('password', 'password_confirmation', 'cf-turnstile-response'))
                ->withErrors(['phone' => 'Enter a valid phone number, for example 0912345678.']);
        }

        if (Str::contains($validated['password'], [$normalizedPhone, ltrim($validated['phone'], '0+')])) {
            throw ValidationException::withMessages(['password' => 'Your password must not contain your phone number.']);
        }

        // Archived accounts keep their number reserved, so they are checked too — otherwise
        // the insert would fail on the unique index with an unexplained error.
        $existing = User::withTrashed()->whereIn('phone', PhoneNumber::candidates($normalizedPhone))->first();
        if ($existing) {
            SecurityEvent::record(SecurityEvent::TYPE_REGISTRATION, SecurityEvent::OUTCOME_BLOCKED, 'duplicate_phone', $request->ip(), $request->userAgent(), $normalizedPhone, $existing->id);

            return back()->withInput($request->except('password', 'password_confirmation', 'cf-turnstile-response'))
                ->withErrors(['phone' => $existing->trashed()
                    ? 'This phone number belongs to a closed account. Please contact support.'
                    : 'An account already uses this phone number. Please log in instead.']);
        }

        $welcomeBalance = (int) PlatformSetting::valueFor('welcome_credits', 5);
        $firstName = Str::squish($validated['first_name']);
        $lastName = Str::squish($validated['last_name']);
        $fullName = trim($firstName.' '.$lastName);

        $user = UserRepository::create([
            'name' => $fullName,
            'username' => $validated['username'] ?? $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $normalizedPhone,
            'email' => $email,
            'password' => $validated['password'],
            'affiliate_id' => $validated['referral_code'] ?? null,
            'role' => User::ROLE_PLAYER,
            'credits' => $welcomeBalance,
            'registration_ip' => $request->ip(),
        ]);

        WalletTransaction::create([
            'ledger_key' => 'registration-welcome-'.$user->id,
            'user_id' => $user->id, 'type' => 'welcome', 'amount' => $welcomeBalance,
            'status' => 'success', 'method' => 'system', 'reason' => 'New-player welcome balance',
            'currency' => 'ETB', 'source_type' => 'registration', 'source_id' => $user->id,
        ]);

        $guard->recordSuccess($request, $user);

        $sent = $email !== null && $verification->send($user);

        Auth::login($user);
        $request->session()->regenerate();

        $status = 'Welcome to Birr Games. Your '.Money::format($welcomeBalance).' balance is ready.';
        if ($sent) {
            $status .= ' We sent a confirmation link to '.$email.'.';
        }

        // Offer to connect the Telegram bot right away, while they are paying attention. It is
        // optional: the page has a Skip button and the account works without it.
        if (app(\App\Services\TelegramAccountLinker::class)->available()) {
            return redirect()->route('player.telegram.show', ['welcome' => 1])->with('status', $status);
        }

        return redirect()->route('home')->with('status', $status);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
