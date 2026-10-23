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

// Deposit bonus: take back what has run out of time, and forget finished rounds.
// Both do nothing until the deposit-bonus migration has been applied.
Schedule::call(fn () => app(\App\Services\DepositBonusService::class)->expireDue())
    ->name('expire-deposit-bonuses')
    ->hourly()
    ->withoutOverlapping();
Schedule::call(fn () => \App\Services\BonusPlay::prune())
    ->name('prune-bonus-stakes')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// Passwords the bot sent are removed from the chat a few minutes later. A failed delete
// (network, rate limit) is tried again on the next run, and given up on after two hours.
Schedule::call(function (): void {
    if (! \Illuminate\Support\Facades\Schema::hasTable('telegram_secret_messages')) {
        return;
    }

    $bot = app(\App\Services\TelegramBotService::class);
    \Illuminate\Support\Facades\DB::table('telegram_secret_messages')
        ->where('delete_after', '<=', now())->orderBy('id')->limit(100)->get()
        ->each(function ($row) use ($bot): void {
            try {
                $bot->deleteMessage($row->chat_id, $row->message_id);
            } catch (\Throwable) {
                if (\Illuminate\Support\Carbon::parse($row->delete_after)->gt(now()->subHours(2))) {
                    return;
                }
            }
            \Illuminate\Support\Facades\DB::table('telegram_secret_messages')->where('id', $row->id)->delete();
        });
})->name('purge-telegram-secrets')->everyMinute()->withoutOverlapping();
