<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index('created_at', 'wallet_transactions_created_at_index');
            $table->index('amount', 'wallet_transactions_amount_index');
            $table->index('fee', 'wallet_transactions_fee_index');
            $table->index('method', 'wallet_transactions_method_index');
            $table->index('bank_code', 'wallet_transactions_bank_code_index');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wallet_transactions_created_at_index');
            $table->dropIndex('wallet_transactions_amount_index');
            $table->dropIndex('wallet_transactions_fee_index');
            $table->dropIndex('wallet_transactions_method_index');
            $table->dropIndex('wallet_transactions_bank_code_index');
        });
    }
};
