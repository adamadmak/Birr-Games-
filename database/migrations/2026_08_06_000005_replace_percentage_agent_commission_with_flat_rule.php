<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commission used to be a percentage of every deposit and every paid withdrawal, so a
 * 100 ETB deposit earned 10 ETB and a 998 ETB withdrawal earned 99.80. It is now a single
 * flat rule: 200 ETB on a deposit or withdrawal that reaches 10,000 ETB, and nothing
 * otherwise. Per-agent rates are retired; the enable switch per agent still applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'agent_commission_amount' => '200',
            'agent_commission_threshold' => '10000',
            'agent_commission_mode' => 'per_order',
        ] as $key => $value) {
            DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
        }

        DB::table('platform_settings')->where('key', 'default_agent_commission')->delete();
        DB::table('agents')->where('commission_rate', '>', 0)->update(['commission_rate' => 0]);
    }

    public function down(): void
    {
        DB::table('platform_settings')
            ->whereIn('key', ['agent_commission_amount', 'agent_commission_threshold', 'agent_commission_mode'])
            ->delete();

        DB::table('platform_settings')->updateOrInsert(['key' => 'default_agent_commission'], ['value' => '0']);
    }
};
