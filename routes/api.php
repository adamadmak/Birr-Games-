<?php

use App\Http\Controllers\BigBangCatalogSyncController;
use App\Http\Controllers\BigBangWalletController;
use App\Http\Controllers\QtechCatalogSyncController;
use App\Http\Controllers\QtechWalletController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\WinhouseWalletController;
use Illuminate\Support\Facades\Route;

Route::get('/integrations/bigbang/user-data', [BigBangWalletController::class, 'userData'])
    ->middleware('throttle:6000,1,integrations-bigbang-user-data')
    ->name('integrations.bigbang.user-data');

Route::post('/integrations/bigbang/balance-change', [BigBangWalletController::class, 'balanceChange'])
    ->middleware('throttle:20000,1,integrations-bigbang-balance-change')
    ->name('integrations.bigbang.balance-change');

// Catalog refresh for an external timer. The hourly schedule in routes/console.php
// does not fire on this host, so without this the lobby only updates when an admin
// clicks Sync and every BigBang portal change stays invisible to players.
Route::post('/integrations/bigbang/sync-catalog', BigBangCatalogSyncController::class)
    ->middleware('throttle:6,1,integrations-bigbang-sync-catalog')
    ->name('integrations.bigbang.sync-catalog');

// The QTech catalog refresh, driven by the same external timer.
Route::post('/integrations/qtech/sync-catalog', QtechCatalogSyncController::class)
    ->middleware('throttle:6,1,integrations-qtech-sync-catalog')
    ->name('integrations.qtech.sync-catalog');

// The scheduler's clock. The same timer calls this once a minute with the same
// token; it runs schedule:run, which includes the queue worker (SystemTickController).
// What an uptime monitor asks once a minute: database, scheduler freshness,
// schema. No secret, nothing player-specific (SystemHealthController).
Route::get('/system/health', \App\Http\Controllers\SystemHealthController::class)
    ->middleware('throttle:60,1,system-health')
    ->name('system.health');

Route::match(['get', 'post'], '/system/tick', \App\Http\Controllers\SystemTickController::class)
    ->middleware('throttle:6,1,system-tick')
    ->name('system.tick');

// WinHouse sportsbook. `transaction` is read-only on purpose: it lets the book's
// sweeper resolve a stake whose call timed out instead of blind-retrying it,
// which would charge a player twice for one bet.
Route::get('/integrations/winhouse/user-data', [WinhouseWalletController::class, 'userData'])
    ->middleware('throttle:6000,1,integrations-winhouse-user-data')
    ->name('integrations.winhouse.user-data');

Route::post('/integrations/winhouse/balance-change', [WinhouseWalletController::class, 'balanceChange'])
    ->middleware('throttle:20000,1,integrations-winhouse-balance-change')
    ->name('integrations.winhouse.balance-change');

Route::get('/integrations/winhouse/transaction', [WinhouseWalletController::class, 'transaction'])
    ->middleware('throttle:20000,1,integrations-winhouse-transaction')
    ->name('integrations.winhouse.transaction');

// QTech (QT) Common Wallet. QTech host the games and call these back for every
// bet, win and refund; the Pass-Key header is the only authentication, so every
// handler verifies it first and fails closed when it is unset.
//
// Base URL handed to QTech: https://lucychewata.com/api/integrations/qtech/
//
// Throttles are generous on purpose. A live table can settle many rounds a
// second per player and QT retry anything that times out, so a limit tight
// enough to bite would turn a traffic spike into refused bets and stuck rounds.
Route::prefix('/integrations/qtech')->name('integrations.qtech.')->group(function () {
    Route::get('/accounts/{playerId}/session', [QtechWalletController::class, 'verifySession'])
        ->middleware('throttle:6000,1,verify-session')
        ->name('verify-session');

    Route::get('/accounts/{playerId}/balance', [QtechWalletController::class, 'getBalance'])
        ->middleware('throttle:6000,1,get-balance')
        ->name('get-balance');

    // Withdrawal (txnType=DEBIT) and deposit (txnType=CREDIT) share this URL —
    // that is QT's contract, not a shortcut.
    Route::post('/transactions', [QtechWalletController::class, 'transaction'])
        ->middleware('throttle:20000,1,transaction')
        ->name('transaction');

    Route::post('/transactions/rollback', [QtechWalletController::class, 'rollback'])
        ->middleware('throttle:20000,1,rollback')
        ->name('rollback');

    Route::post('/bonus/rewards', [QtechWalletController::class, 'reward'])
        ->middleware('throttle:600,1,reward')
        ->name('reward');

    Route::post('/bonus/status', [QtechWalletController::class, 'promotionStatus'])
        ->middleware('throttle:600,1,promotion-status')
        ->name('promotion-status');
});

// The same endpoints again on their own prefix, for QTech's staging
// environment. They register a Base URL per environment and will not accept the
// same one twice, and this deployment has no separate staging system — which
// QTech have been told plainly. What the prefix does buy is a genuinely
// separate secret: staging resolves `qtech_pass_key_staging`, so a staging key
// that leaks cannot authorise anything on the production path, and staging
// stays refused entirely until that key is set.
//
// Both prefixes reach the same application and the same database, so a
// transaction QTech send here is a real transaction against a real player.
// Point them at a dedicated test account, never a live one.
Route::prefix('/integrations/qtech-staging')->name('integrations.qtech-staging.')->group(function () {
    Route::get('/accounts/{playerId}/session', [QtechWalletController::class, 'verifySession'])
        ->middleware('throttle:6000,1,qts-verify-session')
        ->name('verify-session');

    Route::get('/accounts/{playerId}/balance', [QtechWalletController::class, 'getBalance'])
        ->middleware('throttle:6000,1,qts-get-balance')
        ->name('get-balance');

    // Withdrawal (txnType=DEBIT) and deposit (txnType=CREDIT) share this URL —
    // that is QT's contract, not a shortcut.
    Route::post('/transactions', [QtechWalletController::class, 'transaction'])
        ->middleware('throttle:20000,1,qts-transaction')
        ->name('transaction');

    Route::post('/transactions/rollback', [QtechWalletController::class, 'rollback'])
        ->middleware('throttle:20000,1,qts-rollback')
        ->name('rollback');

    Route::post('/bonus/rewards', [QtechWalletController::class, 'reward'])
        ->middleware('throttle:600,1,qts-reward')
        ->name('reward');

    Route::post('/bonus/status', [QtechWalletController::class, 'promotionStatus'])
        ->middleware('throttle:600,1,qts-promotion-status')
        ->name('promotion-status');
});

Route::post('/integrations/telegram/webhook', TelegramWebhookController::class)
    ->middleware(['throttle:120,1,telegram-webhook', \App\Http\Middleware\DeduplicateTelegramUpdate::class])
    ->name('telegram.webhook');
