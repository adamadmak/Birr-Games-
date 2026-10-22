<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->string('ledger_key', 190)->nullable()->unique()->after('transaction_id');
        });

        Schema::table('agent_orders', function (Blueprint $table): void {
            $table->foreignId('reviewed_by_user_id')->nullable()->after('processed_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
            $table->foreignId('payout_submitted_by_agent_id')->nullable()->after('reviewed_at')
                ->constrained('agents')->nullOnDelete();
            $table->timestamp('payout_submitted_at')->nullable()->after('payout_submitted_by_agent_id');
            $table->string('payout_reference', 120)->nullable()->after('payout_submitted_at');
            $table->string('payout_proof_path')->nullable()->after('payout_reference');
            $table->index(['type', 'status', 'reviewed_at'], 'agent_orders_settlement_review_idx');
        });

        DB::table('agents')->orderBy('id')->each(function ($agent): void {
            $recorded = (float) DB::table('wallet_transactions')
                ->where('agent_id', $agent->id)
                ->whereNull('user_id')
                ->where('status', 'success')
                ->sum('amount');
            $difference = round((float) $agent->balance - $recorded, 2);

            if (abs($difference) >= 0.01) {
                DB::table('wallet_transactions')->insert([
                    'transaction_id' => (string) \Illuminate\Support\Str::uuid(),
                    'ledger_key' => 'agent-baseline-'.$agent->id,
                    'agent_id' => $agent->id,
                    'type' => 'agent_opening_balance',
                    'amount' => $difference,
                    'fee' => 0,
                    'status' => 'success',
                    'method' => 'migration',
                    'reason' => 'Agent balance reconciliation baseline',
                    'currency' => 'ETB',
                    'source_type' => 'agent',
                    'source_id' => $agent->id,
                    'metadata' => json_encode(['migration' => '2026_07_23_000029']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_orders', function (Blueprint $table): void {
            $table->dropIndex('agent_orders_settlement_review_idx');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropConstrainedForeignId('payout_submitted_by_agent_id');
            $table->dropColumn([
                'reviewed_at',
                'payout_submitted_at',
                'payout_reference',
                'payout_proof_path',
            ]);
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropUnique('wallet_transactions_ledger_key_unique');
            $table->dropColumn('ledger_key');
        });
    }
};
