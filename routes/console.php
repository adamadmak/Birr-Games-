<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Cache;
use App\Models\BigBangSession;

Schedule::command('queue:prune-batches')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('wallet:reconcile --json')->hourly()->withoutOverlapping();
Schedule::command('queue:work database --queue=telegram,default --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
Schedule::call(fn () => Cache::forever('operations.scheduler_heartbeat', now()->toIso8601String()))
    ->name('operations-scheduler-heartbeat')
    ->everyMinute();
Schedule::call(fn () => BigBangSession::query()
    ->where('status', 'active')
    ->where(fn ($query) => $query
        ->where('expires_at', '<=', now())
        ->orWhere('last_activity_at', '<=', now()->subHours(6)))
    ->update(['status' => 'expired', 'updated_at' => now()]))
    ->name('expire-bigbang-sessions')
    ->everyTenMinutes()
    ->withoutOverlapping();

// The catalog was previously refreshed only when an admin clicked Sync, so the lobby
// drifted from the account: providers switched off in the BigBang portal kept showing
// and newly activated ones never appeared. The feed is already filtered per API key,
// so re-running it on a schedule is what keeps the lobby equal to what the account
// actually carries.
Schedule::command('bigbang:sync-catalog')
    ->name('bigbang-sync-catalog')
    ->hourlyAt(19)
    ->withoutOverlapping();

// QTech, on the same footing. Offset from BigBang so the two never share a slot.
Schedule::command('qtech:sync-catalog')
    ->name('qtech-sync-catalog')
    ->hourlyAt(37)
    ->withoutOverlapping();

Schedule::call(fn () => \App\Models\QtechSession::query()
    ->where('status', 'active')
    ->where(fn ($query) => $query
        ->where('expires_at', '<=', now())
        ->orWhere('last_activity_at', '<=', now()->subHours(6)))
    ->update(['status' => 'expired', 'updated_at' => now()]))
    ->name('expire-qtech-sessions')
    ->everyTenMinutes()
    ->withoutOverlapping();
