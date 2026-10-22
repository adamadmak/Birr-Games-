<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('accent', 20)->default('cyan');
            $table->boolean('active')->default(true);
            $table->boolean('content_server_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('game_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('game_providers')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('game_categories')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('tag', 30)->nullable();
            $table->string('art', 30)->default('dice');
            $table->string('symbol', 10)->default('✦');
            $table->string('agent', 50)->nullable();
            $table->boolean('playable')->default(false);
            $table->boolean('top_game')->default(false);
            $table->boolean('crash')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('game_lobbies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('game_lobby_game', function (Blueprint $table) {
            $table->foreignId('lobby_id')->constrained('game_lobbies')->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['lobby_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_lobby_game');
        Schema::dropIfExists('game_lobbies');
        Schema::dropIfExists('games');
        Schema::dropIfExists('game_categories');
        Schema::dropIfExists('game_providers');
    }
};
