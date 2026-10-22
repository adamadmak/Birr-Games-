<?php

namespace App\Console\Commands;

use App\Services\WalletReconciliationService;
use Illuminate\Console\Command;

class ReconcileWallets extends Command
{
    protected $signature = 'wallet:reconcile {--json : Return machine-readable JSON}';
    protected $description = 'Detect wallet, ledger, withdrawable, and agent-order inconsistencies without changing balances';

    public function handle(WalletReconciliationService $reconciliation): int
    {
        $result = $reconciliation->inspect();
        $anomalyCount = $result['balance_mismatches']->count()
            + $result['agent_balance_mismatches']->count()
            + $result['invalid_withdrawable']->count()
            + $result['order_ledger_anomalies']->count()
            + $result['commission_anomalies']->count()
            + $result['payout_control_anomalies']->count();

        if ($this->option('json')) {
            $this->line(json_encode([
                'users_checked' => $result['users_checked'],
                'agents_checked' => $result['agents_checked'],
                'anomaly_count' => $anomalyCount,
                'balance_mismatches' => $result['balance_mismatches'],
                'agent_balance_mismatches' => $result['agent_balance_mismatches'],
                'invalid_withdrawable' => $result['invalid_withdrawable'],
                'order_ledger_anomalies' => $result['order_ledger_anomalies'],
                'commission_anomalies' => $result['commission_anomalies'],
                'payout_control_anomalies' => $result['payout_control_anomalies'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Players checked: '.$result['users_checked']);
            $this->info('Agents checked: '.$result['agents_checked']);
            $this->line('Balance mismatches: '.$result['balance_mismatches']->count());
            $this->line('Agent balance mismatches: '.$result['agent_balance_mismatches']->count());
            $this->line('Invalid withdrawable balances: '.$result['invalid_withdrawable']->count());
            $this->line('Order/ledger anomalies: '.$result['order_ledger_anomalies']->count());
            $this->line('Commission anomalies: '.$result['commission_anomalies']->count());
            $this->line('Payout control anomalies: '.$result['payout_control_anomalies']->count());
            if ($anomalyCount > 0) {
                $this->warn('Reconciliation found '.$anomalyCount.' anomaly/anomalies. No balances were changed.');
            } else {
                $this->info('Wallet reconciliation passed.');
            }
        }

        return $anomalyCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
