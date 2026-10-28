<?php

/*
 * Switching language returns to the page the player was reading. The wallet
 * page polls the balance in the background; that poll must not become the
 * "previous page", or the switch lands on raw JSON.
 */

it('returns to the page the player was on, not to the background balance poll', function () {
    $user = player(100);

    test()->actingAs($user)->get('/player/wallet')->assertOk();
    test()->actingAs($user)->get('/player/balance', ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->assertOk();

    test()->actingAs($user)->get('/language/am')
        ->assertRedirect(url('/player/wallet'))
        ->assertCookie(\App\Http\Middleware\SetLocale::COOKIE, 'am');
});

it('refuses a language the site is not translated into', function () {
    test()->get('/language/fr')->assertNotFound();
});
