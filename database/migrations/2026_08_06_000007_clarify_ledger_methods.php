<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Method column said "Agent" for both a deposit the agent funded from their own float
 * and one a player paid into a bank that the agent merely verified, and "System" for the
 * agent float counter-entry. Three different things, two indistinguishable labels.
 *
 * History is relabelled here so old rows read the same way as new ones. The ledger keys
 * are unambiguous about which path wrote each row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The agent funded it themselves.
        DB::table('wallet_transactions')
            ->where('type', 'deposit')
            ->whereIn('method', ['agent', 'system'])
            ->where('ledger_key', 'like', 'agent-direct-deposit-%')
            ->update(['method' => 'agent_direct']);

        // The player paid a bank; the agent verified the reference.
        DB::table('wallet_transactions')
            ->where('type', 'deposit')
            ->whereIn('method', ['agent', 'system'])
            ->where('ledger_key', 'like', 'player-deposit-order-%')
            ->update(['method' => 'agent_approved']);

        // Any remaining agent-made deposit predates ledger keys; treat it as agent funded.
        DB::table('wallet_transactions')
            ->where('type', 'deposit')
            ->where('method', 'agent')
            ->whereNotNull('agent_id')
            ->update(['method' => 'agent_direct']);

        // The agent float side of a settlement, whatever wrote it.
        DB::table('wallet_transactions')
            ->where('method', 'system')
            ->whereNotNull('agent_id')
            ->where('type', 'like', 'agent_%')
            ->update(['method' => 'agent_float']);

        DB::table('wallet_transactions')
            ->where('method', 'agent')
            ->where('type', 'agent_transfer')
            ->update(['method' => 'agent_transfer']);
    }

    public function down(): void
    {
        DB::table('wallet_transactions')
            ->whereIn('method', ['agent_direct', 'agent_approved', 'agent_transfer'])
            ->update(['method' => 'agent']);

        DB::table('wallet_transactions')
            ->where('method', 'agent_float')
            ->update(['method' => 'system']);
    }
};
