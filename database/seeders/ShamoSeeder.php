<?php

namespace Database\Seeders;

use App\Models\ShamoCampaign;
use Illuminate\Database\Seeder;

class ShamoSeeder extends Seeder
{
    public function run(): void
    {
        ShamoCampaign::query()->updateOrCreate(
            ['name' => 'Vamoseone Launch Sprint'],
            [
                'description' => 'Join the launch campaign, play local agents, and compete for demo-credit rewards.',
                'entry_cost' => 250,
                'reward_pool' => 15_000,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(14),
                'status' => ShamoCampaign::STATUS_ACTIVE,
            ]
        );

        ShamoCampaign::query()->updateOrCreate(
            ['name' => 'Weekend Agent Cup'],
            [
                'description' => 'A free-entry campaign for players exploring Lucky Dice, Coin Flip, and High / Low.',
                'entry_cost' => 0,
                'reward_pool' => 8_000,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(7),
                'status' => ShamoCampaign::STATUS_ACTIVE,
            ]
        );
    }
}
