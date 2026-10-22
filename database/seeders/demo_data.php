<?php
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

if (User::count() > 10) { echo "already seeded\n"; return; }

$names = ['Abel Tesfaye','Hana Girma','Dawit Bekele','Selam Abera','Yonas Kebede','Marta Alemu','Samuel Tadesse','Liya Haile','Biruk Assefa','Eden Mulugeta','Kaleb Worku','Sara Negash','Natnael Fikre','Ruth Desta','Elias Getachew','Mahi Solomon','Robel Mekonnen','Betty Ayele','Henok Zewde','Feven Tsegaye'];
$games = ['lucky-dice','coin-flip','high-low','sky-dash','sheba-gold'];
$users = [];
foreach ($names as $i => $name) {
    $users[] = User::create([
        'name' => $name,
        'email' => Str::slug($name, '.') . '@example.com',
        'password' => Hash::make('player1234'),
        'role' => 'player',
        'credits' => rand(500, 90000),
        'email_verified_at' => now()->subDays(rand(1, 60)),
        'created_at' => now()->subDays(rand(1, 90)),
    ]);
}
$rows = [];
foreach (range(1, 400) as $i) {
    $stake = rand(20, 2500);
    $won = rand(1, 100) <= 42;
    $created = now()->subMinutes(rand(0, 60 * 24 * 30));
    $rows[] = [
        'user_id' => $users[array_rand($users)]->id,
        'game' => $games[array_rand($games)],
        'stake' => $stake,
        'payout' => $won ? (int) round($stake * (rand(110, 350) / 100)) : 0,
        'result' => $won ? 'won' : 'lost',
        'created_at' => $created,
        'updated_at' => $created,
    ];
}
DB::table('game_rounds')->insert($rows);
echo "seeded " . count($users) . " players, 400 rounds\n";
