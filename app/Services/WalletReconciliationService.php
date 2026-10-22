<?php

namespace App\Services;

use App\Models\AgentOrder;
use App\Models\Agent;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class WalletReconciliationService
{
    private const ANOMALY_CACHE_KEY = 'operations:anomaly-count';

    /**
     * The number of open anomalies, cached.
     *
     * inspect() walks every player and aggregates the whole ledger, which is right for the
     * Reconciliation page but far too heavy for a dashboard badge that reloads constantly.
     * The figure is refreshed on a short interval instead, and any reconciliation run
     * clears it so a fix shows up immediately.
     */
    public function cachedAnomalyCount(int $minutes = 10): int
    {
        return (int) Cache::remember(
            self::ANOMALY_CACHE_KEY,
            now()->addMinutes(max(1, $minutes)),
            fn (): int => $this->anomalyCount()
        );
    }

    public function anomalyCount(): int
    {
        $report = $this->inspect();

        return collect([
            'balance_mismatches',
            'agent_balance_mismatches',
            'invalid_withdrawable',
            'order_ledger_anomalies',
            'commission_anomalies',
            'payout_control_anomalies',
        ])->sum(fn (string $key) => $report[$key] instanceof Collection ? $report[$key]->count() : 0);
    }

    public static function forgetCachedAnomalyCount(): void
    {
        Cache::forget(self::ANOMALY_CACHE_KEY);
    }

    /** @return array<string, int|Collection> */
    public function inspect(): array
    {
        $users = User::query()
            ->where('role', User::ROLE_PLAYER)
            ->select(['id', 'name', 'credits', 'withdrawable_balance'])
            ->get();

        $ledgerBalances = WalletTransaction::query()
            ->selectRaw('user_id, COALESCE(SUM(amount), 0) AS ledger_balance')
            ->whereNotNull('user_id')
            ->whereIn('status', ['success', 'pending'])
            ->groupBy('user_id')
            ->pluck('ledger_balance', 'user_id');

        $balanceMismatches = $users
            ->filter(fn (User $user) => round((float) $user->credits, 2) !== round((float) ($ledgerBalances[$user->id] ?? 0), 2))
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'name' => $user->name,
                'wallet_balance' => round((float) $user->credits, 2),
                'ledger_balance' => round((float) ($ledgerBalances[$user->id] ?? 0), 2),
            ])->values();

        $invalidWithdrawable = $users
            ->filter(fn (User $user) => (float) $user->withdrawable_balance < 0 || (float) $user->withdrawable_balance > (float) $user->credits)
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'name' => $user->name,
                'wallet_balance' => round((float) $user->credits, 2),
                'withdrawable_balance' => round((float) $user->withdrawable_balance, 2),
            ])->values();

        $orderLedgerAnomalies = AgentOrder::query()
            ->whereIn('type', ['deposit', 'withdrawal'])
            ->whereIn('status', ['pending', 'success', 'rejected', 'cancelled'])
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('wallet_transactions')
                ->whereColumn('wallet_transactions.source_id', 'agent_orders.id')
                ->where('wallet_transactions.source_type', 'agent_order'))
            ->where(fn ($query) => $query
                ->where('type', 'withdrawal')
                ->orWhere(fn ($nested) => $nested->where('type', 'deposit')->where('status', 'success')))
            ->select(['id', 'user_id', 'type', 'amount', 'status', 'reference'])
            ->get();

        $agents = Agent::query()->select(['id', 'nickname', 'phone', 'balance'])->get();
        $agentLedgerBalances = WalletTransaction::query()
            ->selectRaw('agent_id, COALESCE(SUM(amount), 0) AS ledger_balance')
            ->whereNotNull('agent_id')
            ->whereNull('user_id')
            ->where('status', 'success')
            ->groupBy('agent_id')
            ->pluck('ledger_balance', 'agent_id');

        $agentBalanceMismatches = $agents
            ->filter(fn (Agent $agent) => round((float) $agent->balance, 2) !== round((float) ($agentLedgerBalances[$agent->id] ?? 0), 2))
            ->map(fn (Agent $agent) => [
                'agent_id' => $agent->id,
                'name' => $agent->nickname ?: $agent->phone,
                'wallet_balance' => round((float) $agent->balance, 2),
                'ledger_balance' => round((float) ($agentLedgerBalances[$agent->id] ?? 0), 2),
            ])->values();

        $commissionAnomalies = AgentOrder::query()
            ->where('status', 'success')
            ->where('commission', '>', 0)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('wallet_transactions')
                ->whereColumn('wallet_transactions.source_id', 'agent_orders.id')
                ->where('wallet_transactions.source_type', 'agent_order')
                ->where('wallet_transactions.type', 'agent_commission')
                ->where('wallet_transactions.status', 'success'))
            ->select(['id', 'agent_id', 'type', 'commission', 'status', 'reference'])
            ->get();

        $payoutControlAnomalies = AgentOrder::query()
            ->whereIn('type', ['withdrawal', 'manual_withdrawal'])
            ->where('status', 'success')
            ->where(fn ($query) => $query
                ->whereNull('reviewed_by_user_id')
                ->orWhereNull('payout_submitted_by_agent_id')
                ->orWhereNull('payout_reference')
                ->orWhereNull('payout_proof_path'))
            ->select(['id', 'agent_id', 'user_id', 'type', 'amount', 'status', 'reference'])
            ->get();

        return [
            'users_checked' => $users->count(),
            'agents_checked' => $agents->count(),
            'balance_mismatches' => $balanceMismatches,
            'agent_balance_mismatches' => $agentBalanceMismatches,
            'invalid_withdrawable' => $invalidWithdrawable,
            'order_ledger_anomalies' => $orderLedgerAnomalies,
            'commission_anomalies' => $commissionAnomalies,
            'payout_control_anomalies' => $payoutControlAnomalies,
        ];
    }
}
