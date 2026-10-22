<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rounds', function (Blueprint $table) {
            $table->string('provider', 40)->nullable()->after('game');
            $table->string('external_round_id', 120)->nullable()->index()->after('provider');
            $table->string('provider_session_id', 120)->nullable()->index()->after('external_round_id');
        });

        Schema::create('bigbang_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('external_session_id', 120)->unique();
            $table->string('mode', 20);
            $table->string('currency', 10);
            $table->string('status', 30)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bigbang_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider_transaction_id', 120);
            $table->string('action', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_round_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_session_id', 120)->nullable()->index();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 10);
            $table->unsignedBigInteger('response_balance')->default(0);
            $table->string('payload_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['action', 'provider_transaction_id'], 'bigbang_action_transaction_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bigbang_transactions');
        Schema::dropIfExists('bigbang_sessions');
        Schema::table('game_rounds', function (Blueprint $table) {
            $table->dropColumn(['provider', 'external_round_id', 'provider_session_id']);
        });
    }
};
