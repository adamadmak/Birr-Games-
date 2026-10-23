<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per deposit bonus an approved deposit earned. The unique key on
         * the deposit order is what makes a double-clicked or replayed approval
         * pay the bonus once.
         */
        if (! Schema::hasTable('deposit_bonus_grants')) {
            Schema::create('deposit_bonus_grants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('agent_order_id')->nullable()->unique()->constrained('agent_orders')->nullOnDelete();
                $table->decimal('deposit_amount', 16, 2);
                $table->decimal('percent', 6, 2);
                $table->decimal('amount', 16, 2);
                // Forfeited so far: expiry, a withdrawal, or an administrator.
                $table->decimal('forfeited', 16, 2)->default(0);
                $table->string('status', 16)->default('active');
                $table->string('forfeit_reason', 40)->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
                $table->index(['status', 'expires_at']);
                $table->index('created_at');
            });
        }

        /*
         * Play-only money that is out on a round. A stake paid partly from bonus
         * credit remembers how much of it was bonus, so that when the round pays
         * out the bonus part goes back to the bonus wallet and only the winnings
         * become withdrawable. Without this a bet on a near-even payout turns any
         * bonus into cash.
         */
        if (! Schema::hasTable('bonus_stakes')) {
            Schema::create('bonus_stakes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('source', 16);
                $table->string('round_ref', 120);
                $table->decimal('stake_total', 16, 2)->default(0);
                $table->decimal('bonus_staked', 16, 2)->default(0);
                $table->decimal('paid_total', 16, 2)->default(0);
                $table->decimal('bonus_returned', 16, 2)->default(0);
                $table->timestamps();
                $table->unique(['user_id', 'source', 'round_ref']);
                $table->index('updated_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_stakes');
        Schema::dropIfExists('deposit_bonus_grants');
    }
};
