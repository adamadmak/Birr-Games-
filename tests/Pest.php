<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every feature test gets a fresh in-memory SQLite database (phpunit.xml)
| with all migrations applied, so the money paths are exercised against the
| real schema rather than mocks.
|
*/

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature');
uses(Tests\TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** A signed-up player with the given wallet. */
function player(float $credits = 0.0, ?float $withdrawable = null, array $attributes = []): \App\Models\User
{
    return \App\Models\User::factory()->create(array_merge([
        'credits' => $credits,
        'withdrawable_balance' => $withdrawable ?? $credits,
    ], $attributes));
}
