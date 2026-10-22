<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\AgentPortalController;
use App\Models\Agent;
use App\Models\AgentOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AgentLedgerService;
use App\Services\FinancialRuleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::beginTransaction();
try {
    $admin = User::create([
        'name' => 'Idempotency Administrator',
        'email' => 'idempotency-admin-'.uniqid().'@example.test',
        'password' => 'Password#1234',
        'role' => User::ROLE_ADMIN,
        'admin_role' => User::ADMIN_ROLE_FINANCE,
        'admin_permissions' => User::permissionsForRole(User::ADMIN_ROLE_FINANCE),
        'active' => true,
    ]);
    $player = User::create([
        'name' => 'Idempotency Player',
        'email' => 'idempotency-player-'.uniqid().'@example.test',
        'phone' => '2519'.random_int(10000000, 99999999),
        'password' => 'Password#1234',
        'role' => User::ROLE_PLAYER,
        'credits' => 0,
        'withdrawable_balance' => 0,
        'active' => true,
    ]);
    $sender = Agent::create([
        'first_name' => 'Retry',
        'last_name' => 'Sender',
        'nickname' => 'retry-sender-'.uniqid(),
        'phone' => '2519'.random_int(10000000, 99999999),
        'password' => 'Password#1234',
        'balance' => 100,
        'active' => true,
    ]);
    $receiver = Agent::create([
        'first_name' => 'Retry',
        'last_name' => 'Receiver',
        'nickname' => 'retry-receiver-'.uniqid(),
        'phone' => '2519'.random_int(10000000, 99999999),
        'password' => 'Password#1234',
        'balance' => 0,
        'active' => true,
    ]);

    $memberKey = (string) Str::uuid();
    foreach ([1, 2] as $_attempt) {
        $request = Request::create('/admin/members/'.$player->id.'/deposit', 'POST', [
            'amount' => 50,
            'withdrawable_status' => 'withdrawable',
            'idempotency_key' => $memberKey,
        ]);
        $request->setUserResolver(fn () => $admin);
        $app->make(MemberController::class)->deposit(
            $request,
            $player,
            $app->make(FinancialRuleService::class),
        );
    }
    if ((float) $player->fresh()->credits !== 50.0
        || WalletTransaction::query()->where('ledger_key', 'admin-member-deposit-'.$memberKey)->count() !== 1) {
        throw new RuntimeException('An administrator member deposit was applied more than once.');
    }

    $agentCreditKey = (string) Str::uuid();
    foreach ([1, 2] as $_attempt) {
        $request = Request::create('/admin/agents/'.$sender->id.'/balance', 'PATCH', [
            'operation' => 'add',
            'amount' => 250,
            'idempotency_key' => $agentCreditKey,
        ]);
        $request->setUserResolver(fn () => $admin);
        $app->make(AgentController::class)->updateBalance($request, $sender);
    }
    if ((float) $sender->fresh()->balance !== 350.0
        || WalletTransaction::query()->where('ledger_key', 'admin-agent-credit-'.$agentCreditKey)->count() !== 1) {
        throw new RuntimeException('An administrator agent credit was applied more than once.');
    }

    Auth::guard('agent')->login($sender);
    $directDepositKey = (string) Str::uuid();
    // The same reference is deliberately replayed: an idempotent retry must short-circuit
    // before the duplicate-reference guard, so the retry is a no-op rather than an error.
    $directDepositReference = 'IDEM-'.strtoupper(Str::random(8));
    foreach ([1, 2] as $_attempt) {
        $request = Request::create('/agent/direct-deposit', 'POST', [
            'phone' => $player->phone,
            'amount' => 100,
            'reference' => $directDepositReference,
            'withdrawable_status' => 'withdrawable',
            'idempotency_key' => $directDepositKey,
        ]);
        $app->make(AgentPortalController::class)->storeDirectDeposit(
            $request,
            $app->make(FinancialRuleService::class),
            $app->make(AgentLedgerService::class),
        );
    }
    if ((float) $player->fresh()->credits !== 150.0
        || (float) $sender->fresh()->balance !== 250.0
        || AgentOrder::query()->where('idempotency_key', $directDepositKey)->count() !== 1) {
        throw new RuntimeException('An agent direct deposit was applied more than once.');
    }

    $transferKey = (string) Str::uuid();
    foreach ([1, 2] as $_attempt) {
        $request = Request::create('/agent/credit', 'POST', [
            'phone' => $receiver->phone,
            'amount' => 100,
            'idempotency_key' => $transferKey,
        ]);
        $app->make(AgentPortalController::class)->storeCreditTransfer(
            $request,
            $app->make(FinancialRuleService::class),
        );
    }
    if ((float) $sender->fresh()->balance !== 150.0
        || (float) $receiver->fresh()->balance !== 100.0
        || WalletTransaction::query()->where('ledger_key', 'agent-transfer-'.$transferKey.'-debit')->count() !== 1
        || WalletTransaction::query()->where('ledger_key', 'agent-transfer-'.$transferKey.'-credit')->count() !== 1) {
        throw new RuntimeException('An agent credit transfer was applied more than once.');
    }

    echo "Financial retry idempotency smoke tests passed.\n";
} finally {
    Auth::guard('agent')->logout();
    DB::rollBack();
}
