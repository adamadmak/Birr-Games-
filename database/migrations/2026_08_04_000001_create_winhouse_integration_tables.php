<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WinHouse sportsbook seamless-wallet integration.
 *
 * Deliberately mirrors the BigBang casino tables already in this app so the two
 * integrations behave the same way under retries. Differences are only where the
 * domain differs: a sportsbook has tickets and selections, not game rounds, and
 * a session belongs to the embedded book rather than to one game launch.
 *
 * Amounts are decimal(18,2) to match users.credits — the casino tables store
 * whole ETB in an unsigned integer, which would silently truncate the fractional
 * ETB that a sportsbook settlement can legitimately produce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winhouse_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('external_session_id', 120)->unique();
            $table->string('mode', 20)->default('sandbox');
            $table->string('currency', 10)->default('ETB');
            $table->string('status', 30)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('winhouse_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider_transaction_id', 120);
            // bet | win | void — void refunds a cancelled or settled-void ticket
            $table->string('action', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ticket_id', 120)->nullable()->index();
            $table->string('provider_session_id', 120)->nullable()->index();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('currency', 10)->default('ETB');
            $table->decimal('response_balance', 18, 2)->default(0);
            // sha256 of the normalised payload: lets a repeat of the same
            // transaction id be answered idempotently, and a DIFFERENT payload
            // under the same id be rejected as a conflict rather than applied.
            $table->string('payload_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['action', 'provider_transaction_id'], 'winhouse_action_transaction_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winhouse_transactions');
        Schema::dropIfExists('winhouse_sessions');
    }
};
