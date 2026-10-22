<?php

namespace Database\Seeders;

use App\Models\Promotion;
use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ShamoSeeder::class);

        // No demo players or administrators are seeded: the first administrator is
        // created deliberately on each install, never from a password in the repository.

        $promotions = [
            ['title' => 'ETB 5 welcome balance', 'description' => 'Every new local account starts with an ETB 5 play balance.', 'code' => 'WELCOME10K', 'accent' => 'cyan'],
            ['title' => 'Keep the streak alive', 'description' => 'Return each day to unlock an ETB 500 local reward.', 'code' => 'DAILY500', 'accent' => 'gold'],
        ];

        foreach ($promotions as $promotion) {
            Promotion::query()->updateOrCreate(['code' => $promotion['code']], $promotion + ['active' => true]);
        }

        foreach ([
            'support_email' => 'support@lucyplay.local',
            'welcome_credits' => '5',
            'currency_code' => 'ETB',
            'deposit_bonus_amount' => '2500',
            'maintenance_message' => '',
        ] as $key => $value) {
            PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
