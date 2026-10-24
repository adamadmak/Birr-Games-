<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The in-house studio was seeded as "Lucy Originals" and its name shows on
 * the lobby's studio chips. The brand is now Vamoseone, so rename the row on
 * databases that already have it. The slug is an identifier and stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('game_providers')) {
            return;
        }

        DB::table('game_providers')
            ->where('name', 'Lucy Originals')
            ->update(['name' => 'Vamoseone Originals']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('game_providers')) {
            return;
        }

        DB::table('game_providers')
            ->where('name', 'Vamoseone Originals')
            ->update(['name' => 'Lucy Originals']);
    }
};
