<?php

use App\Http\Controllers\AgentPortalController;
use App\Http\Controllers\PlayerDepositController;
use App\Http\Controllers\PlayerWithdrawalController;
use App\Models\Agent;
use App\Models\AgentOrder;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\AgentLedgerService;
use App\Services\TelegramBotService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::beginTransaction();
try {
    $gateway = PaymentGateway::query()->where('supports_withdrawal', true)->firstOrFail();
    Agent::query()->update(['active' => false]);
    $agent = Agent::create([
        'first_name' => 'Agent', 'last_name' => 'Smoke', 'nickname' => 'agent_'.uniqid(),
        'phone' => '2519'.random_int(10000000, 99999999), 'password' => 'password123',
        'balance' => 5000, 'active' => true,
    ]);
    $agent->bankAccounts()->create([
        'payment_gateway_id' => $gateway->id, 'account_name' => 'Agent Smoke',
        'account_number' => $agent->phone, 'active' => true,
    ]);
    $player = User::create([
        'name' => 'Portal Player', 'first_name' => 'Portal', 'last_name' => 'Player',
        'email' => 'portal-'.uniqid().'@example.test', 'phone' => '2519'.random_int(10000000, 99999999),
        'password' => 'password123', 'role' => User::ROLE_PLAYER, 'credits' => 1000,
        'withdrawable_balance' => 1000, 'active' => true,
    ]);
    $withdrawRequest = Request::create('/player/wallet/withdrawals', 'POST', [
        'amount' => 200, 'payment_gateway_id' => $gateway->id,
        'account_name' => 'Portal Player', 'account_number' => $player->phone,
    ]);
    $withdrawRequest->setUserResolver(fn () => $player);
    $app->make(PlayerWithdrawalController::class)->store($withdrawRequest);

    $order = AgentOrder::query()->where('user_id', $player->id)->latest('id')->firstOrFail();
    if ($order->agent_id !== $agent->id || (float) $player->fresh()->credits !== 800.0 || (float) $player->fresh()->withdrawable_balance !== 800.0) {
        throw new RuntimeException('Withdrawal assignment or fund reservation failed.');
    }

    Auth::guard('agent')->login($agent);
    $processRequest = Request::create('/agent/withdrawals/'.$order->id, 'PATCH', [
        'status' => 'success',
        'payout_reference' => 'PAYOUT-'.strtoupper(uniqid()),
    ]);
    $processRequest->files->set(
        'payout_proof',
        UploadedFile::fake()->create('payout.pdf', 10, 'application/pdf'),
    );
    $app->make(AgentPortalController::class)->processWithdrawal(
        $processRequest,
        $order,
        $app->make(AgentLedgerService::class),
    );
    $order->refresh();
    if ($order->status !== 'success' || (float) $agent->fresh()->balance !== 5200.0 || $player->walletTransactions()->where('source_id', $order->id)->value('status') !== 'success') {
        throw new RuntimeException('Single-step agent payout did not synchronize balances and ledger status.');
    }

    $secondRequest = Request::create('/player/wallet/withdrawals', 'POST', [
        'amount' => 100, 'payment_gateway_id' => $gateway->id,
        'account_name' => 'Portal Player', 'account_number' => $player->phone,
    ]);
    $secondRequest->setUserResolver(fn () => $player->fresh());
    $app->make(PlayerWithdrawalController::class)->store($secondRequest);
    $rejected = AgentOrder::query()->where('user_id', $player->id)->latest('id')->firstOrFail();
    $rejectRequest = Request::create('/agent/withdrawals/'.$rejected->id, 'PATCH', ['status' => 'rejected', 'notes' => 'Invalid payout account']);
    $app->make(AgentPortalController::class)->processWithdrawal(
        $rejectRequest,
        $rejected,
        $app->make(AgentLedgerService::class),
    );
    if ($rejected->fresh()->status !== 'rejected' || (float) $player->fresh()->credits !== 800.0 || (float) $player->fresh()->withdrawable_balance !== 800.0) {
        throw new RuntimeException('Rejected withdrawal did not restore the reserved player funds.');
    }

    $depositReference = 'WEB-SMOKE-'.strtoupper(uniqid());
    $depositRequest = Request::create('/player/wallet/deposits', 'POST', [
        'amount' => 60,
        'agent_bank_account_id' => $agent->bankAccounts()->value('id'),
        'reference' => $depositReference,
    ]);
    $depositRequest->setUserResolver(fn () => $player->fresh());
    $app->make(PlayerDepositController::class)->store($depositRequest);
    $depositOrder = AgentOrder::query()->where('reference', $depositReference)->firstOrFail();
    if ($depositOrder->status !== 'pending' || $depositOrder->agent_id !== $agent->id || (float) $player->fresh()->credits !== 800.0) {
        throw new RuntimeException('Web wallet deposit did not create a pending assigned order without premature credit.');
    }

    $approveDepositRequest = Request::create('/agent/deposit-order/'.$depositOrder->id, 'PATCH', ['status' => 'success']);
    $app->make(AgentPortalController::class)->processDeposit(
        $approveDepositRequest,
        $depositOrder,
        $app->make(TelegramBotService::class),
        $app->make(AgentLedgerService::class),
    );
    if ($depositOrder->fresh()->status !== 'success'
        || (float) $player->fresh()->credits !== 860.0
        || (float) $player->fresh()->withdrawable_balance !== 860.0
        || (float) $agent->fresh()->balance !== 5140.0) {
        throw new RuntimeException('Agent approval did not complete the web wallet deposit correctly.');
    }

    echo "Agent withdrawal and deposit approval smoke tests passed.\n";
} finally {
    if (isset($order) && filled($order->fresh()?->payout_proof_path)) {
        Storage::disk((string) config('filesystems.payout_proof_disk', 'secure'))->delete($order->fresh()->payout_proof_path);
    }
    Auth::guard('agent')->logout();
    DB::rollBack();
}
