<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'currency_code'],
            ['value' => 'ETB'],
        );

        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'deposit_bonus_amount'],
            ['value' => '2500'],
        );

        DB::table('promotions')->where('code', 'WELCOME10K')->update([
            'title' => 'ETB 10 welcome balance',
            'description' => 'Every new local account starts with an ETB 10 play balance.',
        ]);

        DB::table('promotions')->where('code', 'DAILY500')->update([
            'description' => 'Return each day to unlock an ETB 500 local reward.',
        ]);

        DB::table('promotions')->where('code', 'DICE5X')->update([
            'title' => 'Lucky Dice pays 5x',
            'description' => 'Call the exact roll and collect five times your Birr stake.',
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', [
            'currency_code',
            'deposit_bonus_amount',
        ])->delete();
    }
};
