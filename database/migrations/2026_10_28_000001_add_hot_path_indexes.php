<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two indexes the hot paths were missing.
 *
 * The wallet page reads a player's ledger newest-first; without
 * (user_id, created_at) MySQL sorted the player's whole history on every
 * view. The QTech settlement resolves the game's local slug from QTech's id
 * on every callback, inside the transaction that holds the player's row, and
 * neither column it filters on was indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            if (! $this->hasIndex('wallet_transactions', 'wallet_tx_user_created_idx')) {
                $table->index(['user_id', 'created_at'], 'wallet_tx_user_created_idx');
            }
        });

        Schema::table('games', function (Blueprint $table) {
            if (! $this->hasIndex('games', 'games_source_provider_game_idx')) {
                $table->index(['sync_source', 'provider_game_id'], 'games_source_provider_game_idx');
            }
            if (! $this->hasIndex('games', 'games_source_launch_code_idx')) {
                $table->index(['sync_source', 'external_launch_code'], 'games_source_launch_code_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', fn (Blueprint $table) => $table->dropIndex('wallet_tx_user_created_idx'));
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex('games_source_provider_game_idx');
            $table->dropIndex('games_source_launch_code_idx');
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            return Schema::hasIndex($table, $index);
        } catch (\Throwable) {
            return false;
        }
    }
};
