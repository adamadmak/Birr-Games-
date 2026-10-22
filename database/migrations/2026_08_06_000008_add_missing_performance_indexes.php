<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the filters the admin and the live views actually run.
 *
 * Every index here backs a query that was scanning the table:
 *  - the agent report and the agent portal dashboard filter and sum by agent_id over a date
 *    range, and wallet_transactions had no index on agent_id at all;
 *  - Players Online asks bigbang_sessions for active sessions by recency, and that table
 *    carried nothing but the unique on its external id;
 *  - the tournament board and Players Online group game_rounds by user over a day, which
 *    wants created_at leading;
 *  - the member list and the dashboards filter users by role and active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['agent_id', 'created_at'], 'wallet_tx_agent_created_idx');
            $table->index(['agent_id', 'type', 'created_at'], 'wallet_tx_agent_type_created_idx');
            // The agent report aggregates a date range across all agents. Carrying type and
            // amount lets those totals and the pagination count come from the index alone.
            $table->index(['created_at', 'agent_id', 'type', 'amount'], 'wallet_tx_created_agent_type_idx');
        });

        Schema::table('bigbang_sessions', function (Blueprint $table) {
            $table->index(['status', 'last_activity_at'], 'bigbang_sessions_status_activity_idx');
            $table->index(['user_id', 'status'], 'bigbang_sessions_user_status_idx');
            $table->index(['game_id', 'status'], 'bigbang_sessions_game_status_idx');
        });

        Schema::table('game_rounds', function (Blueprint $table) {
            $table->index(['created_at', 'user_id'], 'game_rounds_created_user_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'active'], 'users_role_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wallet_tx_agent_created_idx');
            $table->dropIndex('wallet_tx_agent_type_created_idx');
            $table->dropIndex('wallet_tx_created_agent_type_idx');
        });

        Schema::table('bigbang_sessions', function (Blueprint $table) {
            $table->dropIndex('bigbang_sessions_status_activity_idx');
            $table->dropIndex('bigbang_sessions_user_status_idx');
            $table->dropIndex('bigbang_sessions_game_status_idx');
        });

        Schema::table('game_rounds', function (Blueprint $table) {
            $table->dropIndex('game_rounds_created_user_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_active_idx');
        });
    }
};
