<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'welcome_credits'],
            ['value' => '10'],
        );

        DB::table('promotions')->where('code', 'WELCOME10K')->update([
            'title' => 'ETB 10 welcome balance',
            'description' => 'Every new local account starts with an ETB 10 play balance.',
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('key', 'welcome_credits')->update(['value' => '10000']);
        DB::table('promotions')->where('code', 'WELCOME10K')->update([
            'title' => 'ETB 10,000 welcome balance',
            'description' => 'Every new local account starts with an ETB 10,000 play balance.',
        ]);
    }
};
