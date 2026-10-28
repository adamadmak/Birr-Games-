<?php

use App\Mail\ResetPasswordLink;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/*
 * Getting back into an account: the page points at Telegram for everyone,
 * emails a link only to a confirmed address, answers the same way whether or
 * not the account exists, and a used or stale link does nothing.
 */

function mailOn(): void
{
    test()->setting('mail_host', 'smtp.example.test');
    test()->setting('mail_from_address', 'no-reply@example.test');
}

it('links to recovery from the sign-in page and explains the Telegram route', function () {
    test()->get('/login')->assertOk()->assertSee('/password/forgot', false);
    test()->get('/password/forgot')->assertOk()->assertSee('Telegram');
});

it('answers the same way for an unknown account as for a known one', function () {
    Mail::fake();
    mailOn();
    $known = User::factory()->withVerifiedEmail()->create();

    $unknown = test()->post('/password/email', ['account' => '0999999999']);
    $found = test()->post('/password/email', ['account' => $known->phone]);

    $unknown->assertRedirect('/password/forgot')->assertSessionHas('recovery_sent', true);
    $found->assertRedirect('/password/forgot')->assertSessionHas('recovery_sent', true);

    Mail::assertSent(ResetPasswordLink::class, fn ($mail) => $mail->hasTo($known->email));
    expect(SecurityEvent::query()->where('type', SecurityEvent::TYPE_PASSWORD_RESET)->pluck('reason')->all())
        ->toBe(['unknown_account', 'link_sent']);
});

it('sends nothing to an account without a confirmed email', function () {
    Mail::fake();
    mailOn();
    $user = User::factory()->create(['email' => 'unconfirmed@example.test', 'email_verified_at' => null]);

    test()->post('/password/email', ['account' => $user->username])->assertSessionHas('recovery_sent', true);

    Mail::assertNothingSent();
    expect(SecurityEvent::query()->latest('id')->value('reason'))->toBe('no_confirmed_email');
});

it('changes the password with a valid link, signs other browsers out, and refuses the link a second time', function () {
    $user = User::factory()->withVerifiedEmail()->create(['remember_token' => 'old-remember-token']);
    $token = Password::broker()->createToken($user);

    test()->get('/password/reset/'.$token.'?email='.$user->email)->assertOk()->assertSee('Choose a new password');

    test()->post('/password/reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Brand-new-pass-9',
        'password_confirmation' => 'Brand-new-pass-9',
    ])->assertRedirect('/login')->assertSessionHas('status');

    $user->refresh();
    expect(Hash::check('Brand-new-pass-9', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe('old-remember-token');

    test()->post('/password/reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Another-pass-10',
        'password_confirmation' => 'Another-pass-10',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('Brand-new-pass-9', $user->fresh()->password))->toBeTrue();
});

it('refuses a made-up token and a weak password', function () {
    $user = User::factory()->withVerifiedEmail()->create();

    test()->post('/password/reset', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'Brand-new-pass-9',
        'password_confirmation' => 'Brand-new-pass-9',
    ])->assertSessionHasErrors('email');

    test()->post('/password/reset', [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('Secret123!', $user->fresh()->password))->toBeTrue();
});

it('lets the player sign in with the new password', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $token = Password::broker()->createToken($user);

    test()->post('/password/reset', [
        'token' => $token, 'email' => $user->email,
        'password' => 'Brand-new-pass-9', 'password_confirmation' => 'Brand-new-pass-9',
    ]);

    test()->post('/login', ['phone' => $user->phone, 'password' => 'Brand-new-pass-9'])->assertRedirect();
    test()->assertAuthenticatedAs($user);
});
