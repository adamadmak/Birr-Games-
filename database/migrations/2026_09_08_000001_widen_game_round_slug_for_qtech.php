<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `game_rounds.game` holds a game's slug, and it was sized for slugs we minted
 * ourselves.
 *
 * QTech's catalogue is 10,223 games and their titles collide, so the
 * synchroniser falls back to `qtech-<title>-<random>` and caps it at 140
 * characters (QtechCatalogSynchronizer::upsertGame). Eighty is not enough for
 * those, and the failure would be invisible in the worst place: the round is
 * written on the settlement path, so an over-long slug would throw there and
 * the round would be dropped from every report while the money still moved.
 *
 * 190 matches the slug column on `games` itself and stays inside the index
 * limit for utf8mb4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rounds', function (Blueprint $table): void {
            $table->string('game', 190)->change();
        });
    }

    public function down(): void
    {
        Schema::table('game_rounds', function (Blueprint $table): void {
            $table->string('game', 80)->change();
        });
    }
};
