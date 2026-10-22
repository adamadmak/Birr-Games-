<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QTech (QT) Common Wallet integration.
 *
 * QTech run the games and call back into this wallet for every bet, win and
 * refund, so these two tables are the operator side of that contract: one row
 * per launch session, one row per callback. The callback table is what makes
 * the integration idempotent — QT retries a transaction whenever their 3s
 * connect / 5s read timeout trips, and a retry must return the ORIGINAL
 * response rather than move money twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded: on this platform deploys do not reliably run `migrate`, so
        // tables get created by hand from database/sql/*.sql with nothing
        // recording it. Re-creating one is a hard error that aborts the whole
        // run and blocks every migration queued behind it.
        if (! Schema::hasTable('qtech_sessions')) {
            Schema::create('qtech_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                // The wallet session id we generate at game launch and QT echoes
                // back as the Wallet-Session header on every wallet call.
                $table->string('external_session_id', 120)->unique();
                $table->string('currency', 10)->default('ETB');
                $table->string('status', 30)->default('active');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }

        // Guarded: on this platform deploys do not reliably run `migrate`, so
        // tables get created by hand from database/sql/*.sql with nothing
        // recording it. Re-creating one is a hard error that aborts the whole
        // run and blocks every migration queued behind it.
        if (! Schema::hasTable('qtech_transactions')) {
            Schema::create('qtech_transactions', function (Blueprint $table) {
                $table->id();

                // QT's own transaction id, unique per transaction on their side.
                $table->string('txn_id', 120);
                // DEBIT (bet), CREDIT (win), ROLLBACK (refund) or REWARD.
                $table->string('type', 20);

                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('round_id', 120)->nullable()->index();
                // The bet this row settles or refunds: deposits and rollbacks both
                // carry the original withdrawal's txnId.
                $table->string('bet_id', 120)->nullable()->index();

                $table->decimal('amount', 18, 2)->default(0);
                $table->string('currency', 10)->default('ETB');
                // The balance we answered with. Replaying a retry returns this
                // number rather than recomputing it from a wallet that has since
                // moved on — QT compares the two responses for equality.
                $table->decimal('response_balance', 18, 2)->default(0);

                $table->boolean('rolled_back')->default(false);
                $table->string('payload_hash', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();

                // The idempotency key. QT guarantee txnId is unique per
                // transaction; scoping by type as well costs nothing and keeps a
                // DEBIT and a CREDIT that somehow share an id from colliding.
                $table->unique(['type', 'txn_id'], 'qtech_type_txn_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qtech_transactions');
        Schema::dropIfExists('qtech_sessions');
    }
};
