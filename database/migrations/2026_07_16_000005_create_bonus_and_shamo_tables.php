<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('bonus_type', 60);
            $table->unsignedBigInteger('credits');
            $table->timestamp('claimed_at');
            $table->timestamps();
            $table->unique(['user_id', 'bonus_type']);
        });

        Schema::create('shamo_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('entry_cost')->default(0);
            $table->unsignedBigInteger('reward_pool')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
        });

        Schema::create('shamo_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shamo_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->nullable();
            $table->unsignedBigInteger('prize_amount')->default(0);
            $table->string('status', 20)->default('joined');
            $table->timestamp('joined_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['shamo_campaign_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shamo_participations');
        Schema::dropIfExists('shamo_campaigns');
        Schema::dropIfExists('bonus_claims');
    }
};
