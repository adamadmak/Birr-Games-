<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'min_withdraw_amount'],
            ['value' => '30']
        );

        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'max_withdraw_amount'],
            ['value' => '30000']
        );
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', ['min_withdraw_amount', 'max_withdraw_amount'])->delete();
    }
};
