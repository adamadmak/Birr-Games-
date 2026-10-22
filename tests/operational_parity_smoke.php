<?php

use App\Http\Controllers\Admin\AgentController;
use App\Models\Agent;
use App\Models\AgentOrder;
use App\Models\AgentOrderEvent;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AgentLedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::beginTransaction();
try {
    $admin = User::create([
        'name' => 'P1 Operations Admin',
        'email' => 'p1-admin-'.uniqid().'@example.test',
        'password' => 'password123',
        'role' => User::ROLE_ADMIN,
        'admin_role' => User::ADMIN_ROLE_OPERATIONS,
        'admin_permissions' => User::permissionsForRole(User::ADMIN_ROLE_OPERATIONS),
        'active' => true,
    ]);
    $player = User::create([
        'name' => 'P1 Player',
        'email' => 'p1-player-'.uniqid().'@example.test',
        'phone' => '2519'.random_int(10000000, 99999999),
        'password' => 'password123',
        'role' => User::ROLE_PLAYER,
        'credits' => 0,
        'withdrawable_balance' => 0,
        'active' => true,
    ]);
    $agent = Agent::create([
        'first_name' => 'P1', 'last_name' => 'Agent', 'nickname' => 'p1-'.uniqid(),
        'phone' => '2519'.random_int(10000000, 99999999), 'password' => 'password123',
        'balance' => 1000, 'commission_enabled' => true, 'commission_rate' => 0, 'active' => true,
    ]);
    $gateway = PaymentGateway::query()->where('active', true)->where('supports_deposit', true)->firstOrFail();
    $order = AgentOrder::create([
        'user_id' => $player->id, 'agent_id' => $agent->id, 'payment_gateway_id' => $gateway->id,
        'type' => 'deposit', 'amount' => 100, 'commission' => 0, 'reference' => 'P1-'.uniqid(),
        'account_name' => 'P1 Agent', 'account_number' => '0900000000', 'status' => 'pending',
    ]);
    AgentOrderEvent::record($order, 'submitted', $player, null, 'pending');

    $request = Request::create('/admin/agent-orders/'.$order->id.'/status', 'PATCH', ['status' => 'success']);
    $request->setUserResolver(fn () => $admin);
    $app->make(AgentController::class)->updateOrderStatus(
        $request,
        $order,
        $app->make(AgentLedgerService::class),
    );

    // A 100 ETB deposit is below the commission threshold, so the agent float drops by
    // exactly the funded amount and earns nothing.
    if ((float) $player->fresh()->credits !== 100.0 || (float) $agent->fresh()->balance !== 900.0) {
        throw new RuntimeException('Administrator deposit approval did not move balances exactly once.');
    }
    if ((float) $order->fresh()->commission !== 0.0) {
        throw new RuntimeException('A deposit below the commission threshold paid commission.');
    }
    if (!WalletTransaction::query()->where('source_type', 'agent_order')->where('source_id', $order->id)->where('status', 'success')->exists()) {
        throw new RuntimeException('Administrator deposit approval did not create the ledger record.');
    }
    if (!AgentOrderEvent::query()->where('agent_order_id', $order->id)->where('event', 'approved')->exists()) {
        throw new RuntimeException('Order approval was not added to the operational timeline.');
    }
    if (!$admin->hasAdminPermission('operations.view') || !$admin->hasAdminPermission('audit.view') || !$admin->hasAdminPermission('reconciliation.view')) {
        throw new RuntimeException('Operations role is missing Phase 2 control permissions.');
    }

    echo "Operational queue, approval, ledger, timeline, and permissions smoke tests passed.\n";
} finally {
    DB::rollBack();
}
