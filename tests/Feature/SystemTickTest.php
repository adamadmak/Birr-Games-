<?php

use Illuminate\Support\Facades\Cache;

/*
 * The scheduler tick: refused without the sync token, refused while another
 * run holds the lock. (A real run is not exercised here: schedule:run would
 * start a queue worker process.)
 */

it('refuses a tick without the sync token, and when none is configured', function () {
    config(['services.qtech.sync_token' => '']);
    test()->get('/api/system/tick?token=anything')->assertStatus(401);

    config(['services.qtech.sync_token' => str_repeat('s', 40)]);
    test()->get('/api/system/tick?token=wrong')->assertStatus(401);
    test()->get('/api/system/tick')->assertStatus(401);
});

it('skips a tick while another one is running', function () {
    config(['services.qtech.sync_token' => str_repeat('s', 40)]);
    $lock = Cache::lock('system-tick', 60);
    expect($lock->get())->toBeTrue();

    try {
        test()->get('/api/system/tick?token='.str_repeat('s', 40))->assertStatus(409);
    } finally {
        $lock->release();
    }
});
