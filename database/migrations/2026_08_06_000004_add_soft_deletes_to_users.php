<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Members are archived rather than erased. game_rounds, bonus_claims, member_bonuses and
 * the provider session tables all cascade on delete, and wallet_transactions/agent_orders
 * null their owner, so a hard delete would destroy a player's gaming history and orphan
 * their money trail. Archiving revokes access and hides the account while every financial
 * record stays attributable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
