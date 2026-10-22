<?php

use App\Models\BonusClaim;
use App\Models\User;
use App\Services\WageringService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::beginTransaction();
try {
    $user = User::create([
        'name' => 'Wagering Smoke', 'email' => 'wagering-'.uniqid().'@example.test',
        'password' => 'password', 'role' => User::ROLE_PLAYER, 'credits' => 10000,
        'withdrawable_balance' => 0, 'active' => true,
    ]);
    $claim = $user->bonusClaims()->create([
        'bonus_type' => BonusClaim::FIRST_DEMO_TOP_UP, 'credits' => 2500, 'claimed_at' => now(),
    ]);
    $service = $app->make(WageringService::class);
    $bonus = $service->awardForClaim($user, $claim);
    if (!$bonus || (float) $bonus->rollover_required !== 50000.0) {
        throw new RuntimeException('Bonus award or rollover calculation failed.');
    }
    $service->recordStake($user, 100);
    $bonus->refresh();
    if ((float) $bonus->contributed !== 100.0 || $bonus->status !== 'active') {
        throw new RuntimeException('Stake contribution was not recorded.');
    }
    echo "Wagering integration smoke test passed.\n";
} finally {
    DB::rollBack();
}
