<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_pools', function (Blueprint $table) {
            $table->id();
            $table->string('competition', 120);
            $table->string('home_team', 80);
            $table->string('away_team', 80);
            $table->string('home_crest_path')->nullable();
            $table->string('away_crest_path')->nullable();

            // Money in whole Birr, integers like the rest of the wallet.
            $table->unsignedBigInteger('prize_pool')->default(0);
            $table->unsignedBigInteger('entry_cost')->default(0);
            $table->unsignedBigInteger('min_deposit')->default(0);

            $table->timestamp('kicks_off_at');
            $table->timestamp('closes_at');

            $table->string('status', 20)->default('draft');

            // Filled once the match is played, which settles every pick.
            $table->unsignedTinyInteger('home_score')->nullable();
            $table->unsignedTinyInteger('away_score')->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            // The lobby asks for "the one live pool" on every page load.
            $table->index(['status', 'closes_at']);
        });

        Schema::create('prediction_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('home_score');
            $table->unsignedTinyInteger('away_score');
            $table->boolean('is_winner')->default(false);
            $table->timestamps();

            // One pick per player per pool — the guard lives in the schema, not
            // only in the controller, so a double-submit cannot create two.
            $table->unique(['prediction_pool_id', 'user_id'], 'prediction_entry_unique');
            $table->index(['prediction_pool_id', 'home_score', 'away_score'], 'prediction_entry_scoreline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_entries');
        Schema::dropIfExists('prediction_pools');
    }
};
