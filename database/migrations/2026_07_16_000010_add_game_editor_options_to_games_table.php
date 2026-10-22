<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('demo_supported')->default(false)->after('top_game');
            $table->boolean('free_bet_supported')->default(false)->after('demo_supported');
            $table->string('label_background_color', 7)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['demo_supported', 'free_bet_supported', 'label_background_color']);
        });
    }
};
