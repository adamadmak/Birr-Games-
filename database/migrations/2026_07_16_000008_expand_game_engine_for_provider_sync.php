<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_providers', function (Blueprint $table) {
            $table->string('api_base_url')->nullable()->after('accent');
            $table->boolean('sync_enabled')->default(false)->after('content_server_enabled');
            $table->timestamp('last_synced_at')->nullable()->after('sync_enabled');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->string('provider_game_id')->nullable()->after('category_id');
            $table->string('image_url', 500)->nullable()->after('symbol');
            $table->string('label', 60)->nullable()->after('tag');
            $table->string('external_launch_code')->nullable()->after('agent');
            $table->string('sync_source', 30)->default('local')->after('external_launch_code');
            $table->boolean('content_server_enabled')->default(true)->after('crash');
            $table->timestamp('last_synced_at')->nullable()->after('sort_order');
            $table->unique(['provider_id', 'provider_game_id'], 'games_provider_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropUnique('games_provider_external_unique');
            $table->dropColumn(['provider_game_id', 'image_url', 'label', 'external_launch_code', 'sync_source', 'content_server_enabled', 'last_synced_at']);
        });
        Schema::table('game_providers', function (Blueprint $table) {
            $table->dropColumn(['api_base_url', 'sync_enabled', 'last_synced_at']);
        });
    }
};
