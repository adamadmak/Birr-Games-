<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which fields an administrator set by hand.
 *
 * The catalog sync rewrote every field from the feed on each run, so a game marked as a top
 * game reverted to whatever the provider said — usually not featured — the next time the
 * catalog refreshed. The operator's choice was silently undone minutes later.
 *
 * Fields listed here are left alone by the sync, so a manual decision stands until the same
 * person changes it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->json('manual_overrides')->nullable()->after('sync_source');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('manual_overrides');
        });
    }
};
