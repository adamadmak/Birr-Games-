<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider wallets settle fractional ETB (a 0.5x coefficient on a 5 ETB stake pays
 * 2.50). These columns were whole-number, so every provider bet, win and echoed
 * balance was silently truncated and the wallet drifted away from the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rounds', function (Blueprint $table): void {
            $table->decimal('stake', 18, 2)->change();
            $table->decimal('payout', 18, 2)->default(0)->change();
        });

        Schema::table('bigbang_transactions', function (Blueprint $table): void {
            $table->decimal('amount', 18, 2)->default(0)->change();
            $table->decimal('response_balance', 18, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bigbang_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('amount')->default(0)->change();
            $table->unsignedBigInteger('response_balance')->default(0)->change();
        });

        Schema::table('game_rounds', function (Blueprint $table): void {
            $table->unsignedInteger('stake')->change();
            $table->unsignedInteger('payout')->default(0)->change();
        });
    }
};
