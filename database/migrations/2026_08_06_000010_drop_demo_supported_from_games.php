<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * demo_supported was stored and editable in the admin, but nothing in the launch or lobby
 * flow ever read it — it never gated an actual demo mode. The "Try Demo" buttons it was
 * meant to back turned out to post to the exact same route as "Play Now", so there was no
 * demo path to support. Removed along with the rest of the demo UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('demo_supported');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('demo_supported')->default(false)->after('top_game');
        });
    }
};
