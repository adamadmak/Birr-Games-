<?php

use App\Models\SecurityEvent;
use App\Models\User;

it('signs a player in with any spelling of their phone number', function () {
    $user = User::factory()->create(['phone' => '0912345678']);

    foreach (['0912345678', '+251912345678', '251912345678', '+251 912 345 678'] as $spelling) {
        auth()->logout();
        test()->post('/login', ['phone' => $spelling, 'password' => 'Secret123!'])->assertRedirect();
        test()->assertAuthenticatedAs($user);
    }
});

it('signs a player in with their username, with or without the @', function () {
    $user = User::factory()->create(['username' => 'lucky_one']);

    test()->post('/login', ['phone' => '@lucky_one', 'password' => 'Secret123!'])->assertRedirect();
    test()->assertAuthenticatedAs($user);
});

it('gives the same answer for a wrong password and an unknown account', function () {
    $user = User::factory()->create();

    $wrong = test()->from('/login')->post('/login', ['phone' => $user->phone, 'password' => 'nope-nope-nope']);
    $unknown = test()->from('/login')->post('/login', ['phone' => '0900000000', 'password' => 'nope-nope-nope']);

    $wrong->assertRedirect('/login');
    $unknown->assertRedirect('/login');
    expect(session('errors')->first('phone'))->toBe('Those credentials do not match our records.');
    test()->assertGuest();

    expect(SecurityEvent::query()->where('type', SecurityEvent::TYPE_LOGIN)->where('outcome', SecurityEvent::OUTCOME_FAILED)->count())->toBe(2);
});

it('refuses a disabled account even with the right password', function () {
    $user = User::factory()->inactive()->create();

    test()->post('/login', ['phone' => $user->phone, 'password' => 'Secret123!']);
    test()->assertGuest();
});
