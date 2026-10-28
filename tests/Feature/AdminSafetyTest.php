<?php

use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\GoLiveReset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

it('signs a player out everywhere when an administrator resets their password', function () {
    $admin = User::factory()->admin()->create();
    $player = player(10, 10, ['remember_token' => 'old-remember']);

    test()->actingAs($admin)->patch('/admin/members/'.$player->id.'/password', [
        'password' => 'NewPassword1234',
        'password_confirmation' => 'NewPassword1234',
    ])->assertRedirect();

    $player->refresh();
    expect(Hash::check('NewPassword1234', $player->password))->toBeTrue()
        ->and($player->remember_token)->not->toBe('old-remember');
});

it('runs the go-live reset once, never touches the audit tables, and then locks', function () {
    $player = player(10, 10);
    WalletTransaction::query()->create(['user_id' => $player->id, 'type' => 'bet', 'amount' => -1, 'status' => 'success', 'method' => 'game', 'reason' => 'test', 'currency' => 'ETB']);
    \App\Models\SecurityEvent::record('login', 'failed', 'test', '127.0.0.1', 'pest');

    $reset = app(GoLiveReset::class);
    expect(array_keys($reset->preview()))->not->toContain('audit')->not->toContain('security')
        ->and(GoLiveReset::locked())->toBeFalse();

    $removed = $reset->apply(['wallet'], GoLiveReset::CONFIRMATION);

    expect($removed['wallet'])->toBe(1)
        ->and(\App\Models\SecurityEvent::query()->count())->toBe(1)
        ->and(GoLiveReset::locked())->toBeTrue();

    expect(fn () => $reset->apply(['wallet'], GoLiveReset::CONFIRMATION))->toThrow(RuntimeException::class, 'locked');

    // The admin route refuses too.
    test()->actingAs(User::factory()->admin()->create())
        ->from('/admin/settings?tab=system')
        ->post('/admin/settings/go-live-reset', ['groups' => ['wallet'], 'confirmation' => GoLiveReset::CONFIRMATION])
        ->assertRedirect('/admin/settings?tab=system')
        ->assertSessionHasErrors('go_live_reset');
});

it('answers the health check without secrets and reports a stale scheduler', function () {
    test()->getJson('/api/system/health')->assertStatus(503)->assertJson(['database' => 'ok', 'scheduler' => 'never']);

    Cache::forever(\App\Http\Controllers\SystemTickController::HEARTBEAT_KEY, now()->toIso8601String());
    $fresh = test()->getJson('/api/system/health');
    expect($fresh->json('scheduler'))->toBe('ok')
        ->and($fresh->json())->not->toHaveKey('token');

    Cache::forever(\App\Http\Controllers\SystemTickController::HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String());
    test()->getJson('/api/system/health')->assertStatus(503)->assertJson(['scheduler' => 'stale']);
});
