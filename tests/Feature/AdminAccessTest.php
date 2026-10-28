<?php

use App\Models\User;

/*
 * The admin area is deny-by-default: a route the permission map does not
 * know is refused to every role but the super administrator.
 */

it('keeps guests and players out of the admin area', function () {
    test()->get('/admin/settings')->assertRedirect('/login');

    test()->actingAs(player())->get('/admin/settings')->assertForbidden();
});

it('lets a super administrator in', function () {
    test()->actingAs(User::factory()->admin()->create())->get('/admin/settings?tab=system')->assertOk();
});

it('refuses a limited role a page outside its permissions', function () {
    $support = User::factory()->admin(User::ADMIN_ROLE_SUPPORT)->create();

    test()->actingAs($support)->get('/admin/settings')->assertForbidden();
});

it('signs a disabled administrator out rather than serving the page', function () {
    $admin = User::factory()->admin()->inactive()->create();

    // EnsureAccountIsActive ends the session before the admin check runs.
    test()->actingAs($admin)->get('/admin/settings')->assertRedirect();
    test()->assertGuest();
});
