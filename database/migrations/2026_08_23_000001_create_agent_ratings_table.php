<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A player's verdict on the agent who handled their money.
 *
 * Deposits and withdrawals are settled by a person, and until now nothing recorded
 * whether that person did it well or quickly. The rating is tied to a specific
 * completed order rather than to the agent in general: it can only be left by
 * someone who actually dealt with them, once, about a transaction that finished —
 * which is what makes it worth anything as an accountability signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded: on this platform deploys do not reliably run `migrate`, so
        // tables get created by hand from database/sql/*.sql with nothing
        // recording it. Re-creating one is a hard error that aborts the whole
        // run and blocks every migration queued behind it.
        if (! Schema::hasTable('agent_ratings')) {
            Schema::create('agent_ratings', function (Blueprint $table) {
                $table->id();
                // The order is the entitlement to rate, so it is unique: one verdict per
                // transaction, enforced by the schema rather than only by the controller.
                $table->foreignId('agent_order_id')->unique()->constrained()->cascadeOnDelete();
                $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('score');
                $table->string('comment', 500)->nullable();
                $table->timestamps();

                // The agent list and the agent's own dashboard both average by agent.
                $table->index(['agent_id', 'created_at'], 'agent_ratings_agent_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_ratings');
    }
};
