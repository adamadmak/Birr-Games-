<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('bigbang_transactions')
            ->where('action', 'rollback')
            ->whereNull('original_provider_transaction_id')
            ->update(['original_provider_transaction_id' => DB::raw('provider_transaction_id')]);

        Schema::table('bigbang_transactions', function (Blueprint $table): void {
            $table->unique(
                ['action', 'original_provider_transaction_id'],
                'bigbang_action_original_transaction_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('bigbang_transactions', function (Blueprint $table): void {
            $table->dropUnique('bigbang_action_original_transaction_unique');
        });
    }
};
