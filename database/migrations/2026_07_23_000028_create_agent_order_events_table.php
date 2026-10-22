<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_order_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('event', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agent_order_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });

        DB::table('agent_orders')->orderBy('id')->chunkById(500, function ($orders): void {
            DB::table('agent_order_events')->insert($orders->map(fn ($order) => [
                'agent_order_id' => $order->id,
                'actor_type' => 'system',
                'actor_id' => null,
                'actor_name' => 'Migration',
                'event' => 'imported',
                'from_status' => null,
                'to_status' => $order->status,
                'notes' => 'Existing order imported into the operational event timeline.',
                'metadata' => json_encode(['source' => 'phase_2_backfill']),
                'created_at' => $order->created_at ?? now(),
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_order_events');
    }
};
