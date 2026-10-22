<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('admin_role', 40)->nullable()->after('role');
            $table->json('admin_permissions')->nullable()->after('admin_role');
        });

        DB::table('users')->where('role', 'admin')->update([
            'admin_role' => 'super_admin',
        ]);

        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 120);
            $table->string('route_name', 160)->nullable();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('target_type', 120)->nullable();
            $table->string('target_id', 120)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Schema::table('agent_orders', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique()->after('reference');
            $table->index(['user_id', 'type', 'status', 'created_at'], 'agent_orders_user_type_status_created_idx');
            $table->index(['agent_id', 'status', 'created_at'], 'agent_orders_agent_status_created_idx');
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->index(['user_id', 'status', 'created_at'], 'wallet_transactions_user_status_created_idx');
        });

        DB::table('game_rounds')
            ->select(['provider', 'external_round_id'])
            ->whereNotNull('provider')
            ->whereNotNull('external_round_id')
            ->groupBy('provider', 'external_round_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('provider')
            ->each(function ($duplicate): void {
                $duplicateIds = DB::table('game_rounds')
                    ->where('provider', $duplicate->provider)
                    ->where('external_round_id', $duplicate->external_round_id)
                    ->orderBy('id')
                    ->pluck('id')
                    ->slice(1);
                if ($duplicateIds->isNotEmpty()) {
                    DB::table('game_rounds')->whereIn('id', $duplicateIds)->update(['external_round_id' => null]);
                }
            });

        Schema::table('game_rounds', function (Blueprint $table): void {
            $table->index('created_at', 'game_rounds_created_at_idx');
            $table->index(['user_id', 'created_at'], 'game_rounds_user_created_idx');
            $table->index(['game', 'created_at'], 'game_rounds_game_created_idx');
            $table->unique(['provider', 'external_round_id'], 'game_rounds_provider_round_unique');
        });

        Schema::table('bigbang_transactions', function (Blueprint $table): void {
            $table->string('original_provider_transaction_id', 120)->nullable()->after('provider_transaction_id');
            $table->index('original_provider_transaction_id', 'bigbang_original_transaction_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bigbang_transactions', function (Blueprint $table): void {
            $table->dropIndex('bigbang_original_transaction_idx');
            $table->dropColumn('original_provider_transaction_id');
        });

        Schema::table('game_rounds', function (Blueprint $table): void {
            $table->dropUnique('game_rounds_provider_round_unique');
            $table->dropIndex('game_rounds_created_at_idx');
            $table->dropIndex('game_rounds_user_created_idx');
            $table->dropIndex('game_rounds_game_created_idx');
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropIndex('wallet_transactions_user_status_created_idx');
        });

        Schema::table('agent_orders', function (Blueprint $table): void {
            $table->dropIndex('agent_orders_user_type_status_created_idx');
            $table->dropIndex('agent_orders_agent_status_created_idx');
            $table->dropUnique('agent_orders_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });

        Schema::dropIfExists('admin_activity_logs');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['admin_role', 'admin_permissions']);
        });
    }
};
