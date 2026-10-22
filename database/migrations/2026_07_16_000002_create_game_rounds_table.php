<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game', 80)->index();
            $table->unsignedInteger('stake');
            $table->unsignedInteger('payout')->default(0);
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_rounds');
    }
};
