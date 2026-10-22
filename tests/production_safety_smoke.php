<?php

use App\Http\Controllers\PlayerWithdrawalController;
use App\Models\AgentOrder;
use App\Models\PaymentGateway;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletReconciliationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::beginTransaction();
try {
    foreach ([
        'min_withdraw_amount' => 50,
        'daily_max_withdraw' => 300,
    ] as $key => $value) {
        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
    PlatformSetting::flushValueCache();

    $gateway = PaymentGateway::query()->where('active', true)->where('supports_withdrawal', true)->firstOrFail();
    $player = User::create([
        'name' => 'Safety Player',
        'first_name' => 'Safety',
        'last_name' => 'Player',
        'email' => 'safety-'.uniqid().'@example.test',
        'phone' => '2519'.random_int(10000000, 99999999),
        'password' => 'password123',
        'role' => User::ROLE_PLAYER,
        'credits' => 500,
        'withdrawable_balance' => 500,
        'active' => true,
    ]);
    WalletTransaction::create([
        'user_id' => $player->id,
        'type' => 'opening_balance',
        'amount' => 500,
        'status' => 'success',
        'method' => 'safety_test',
        'reason' => 'Production safety test opening balance',
        'currency' => 'ETB',
    ]);

    $controller = $app->make(PlayerWithdrawalController::class);
    $underMinimum = Request::create('/player/wallet/withdrawals', 'POST', [
        'amount' => 49,
        'payment_gateway_id' => $gateway->id,
        'account_name' => $player->name,
        'account_number' => $player->phone,
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $underMinimum->setUserResolver(fn () => $player);
    try {
        $controller->store($underMinimum);
        throw new RuntimeException('Configured minimum withdrawal was not enforced.');
    } catch (ValidationException) {
    }

    $idempotencyKey = (string) Str::uuid();
    $payload = [
        'amount' => 100,
        'payment_gateway_id' => $gateway->id,
        'account_name' => $player->name,
        'account_number' => $player->phone,
        'idempotency_key' => $idempotencyKey,
    ];
    foreach ([1, 2] as $attempt) {
        $request = Request::create('/player/wallet/withdrawals', 'POST', $payload);
        $request->setUserResolver(fn () => $player->fresh());
        $controller->store($request);
    }

    $matchingOrders = AgentOrder::query()->where('user_id', $player->id)->where('idempotency_key', $idempotencyKey)->count();
    if ($matchingOrders !== 1 || (float) $player->fresh()->credits !== 400.0) {
        throw new RuntimeException('Withdrawal idempotency did not prevent a duplicate reservation.');
    }

    $overDailyLimit = Request::create('/player/wallet/withdrawals', 'POST', [
        'amount' => 250,
        'payment_gateway_id' => $gateway->id,
        'account_name' => $player->name,
        'account_number' => $player->phone,
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $overDailyLimit->setUserResolver(fn () => $player->fresh());
    try {
        $controller->store($overDailyLimit);
        throw new RuntimeException('Configured daily withdrawal limit was not enforced.');
    } catch (ValidationException) {
    }

    $finance = new User([
        'role' => User::ROLE_ADMIN,
        'admin_role' => User::ADMIN_ROLE_FINANCE,
        'admin_permissions' => User::permissionsForRole(User::ADMIN_ROLE_FINANCE),
        'active' => true,
    ]);
    if (! $finance->hasAdminPermission('transactions.view')
        || ! $finance->hasAdminPermission('members.deposit')
        || $finance->hasAdminPermission('members.manage')
        || $finance->hasAdminPermission('settings.manage')) {
        throw new RuntimeException('Administrator permission presets are not enforcing least privilege.');
    }

    $reconciliation = $app->make(WalletReconciliationService::class)->inspect();
    if ($reconciliation['balance_mismatches']->contains('user_id', $player->id)
        || $reconciliation['invalid_withdrawable']->contains('user_id', $player->id)) {
        throw new RuntimeException('A valid wallet failed reconciliation.');
    }

    echo "Production safety limits, idempotency, permissions, and reconciliation smoke tests passed.\n";
} finally {
    DB::rollBack();
    PlatformSetting::flushValueCache();
}
