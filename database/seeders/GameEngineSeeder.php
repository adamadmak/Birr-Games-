<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\GameCategory;
use App\Models\GameLobby;
use App\Models\GameProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GameEngineSeeder extends Seeder
{
    public function run(): void
    {
        $providers = collect([
            ['name' => 'Lucy Originals', 'accent' => 'cyan', 'description' => 'Local, auditable play-money game agents.'],
            ['name' => 'Blue Nile', 'accent' => 'blue', 'description' => 'Classic card and number concepts.'],
            ['name' => 'Rift Labs', 'accent' => 'violet', 'description' => 'Fast experimental game rooms.'],
            ['name' => 'Sheba Arcade', 'accent' => 'gold', 'description' => 'Story-led slot concepts.'],
        ])->mapWithKeys(function ($data) {
            $provider = GameProvider::query()->updateOrCreate(['slug' => Str::slug($data['name'])], $data + ['active' => true, 'content_server_enabled' => true]);
            return [$provider->name => $provider];
        });

        $categories = collect([
            ['name' => 'Originals', 'description' => 'Playable Lucy agents', 'sort_order' => 0],
            ['name' => 'Slots', 'description' => 'Reel-based concepts', 'sort_order' => 1],
            ['name' => 'Quick Games', 'description' => 'Instant outcome games', 'sort_order' => 2],
            ['name' => 'Table Games', 'description' => 'Cards and table classics', 'sort_order' => 3],
        ])->mapWithKeys(function ($data) {
            $category = GameCategory::query()->updateOrCreate(['slug' => Str::slug($data['name'])], $data + ['active' => true]);
            return [$category->name => $category];
        });

        $games = [
            ['title'=>'Lucky Dice','slug'=>'lucky-dice','provider'=>'Lucy Originals','category'=>'Originals','description'=>'Call one of six numbers and win 5× when the die matches.','tag'=>'PLAYABLE','art'=>'dice','symbol'=>'⚄','agent'=>'lucky-dice','playable'=>true,'top_game'=>true],
            ['title'=>'Coin Flip','slug'=>'coin-flip','provider'=>'Lucy Originals','category'=>'Quick Games','description'=>'Heads or tails. A clean 2× local game agent.','tag'=>'NEW AGENT','art'=>'gold','symbol'=>'◎','agent'=>'coin-flip','playable'=>true,'top_game'=>true],
            ['title'=>'High / Low','slug'=>'high-low','provider'=>'Lucy Originals','category'=>'Table Games','description'=>'Choose the side of seven and reveal a card.','tag'=>'NEW AGENT','art'=>'cards','symbol'=>'A','agent'=>'high-low','playable'=>true,'top_game'=>true],
            ['title'=>'Sky Dash','slug'=>'sky-dash','provider'=>'Rift Labs','category'=>'Quick Games','description'=>'A fast rising-flight concept.','tag'=>'ROADMAP','art'=>'sky','symbol'=>'✈'],
            ['title'=>'Sheba Gold','slug'=>'sheba-gold','provider'=>'Sheba Arcade','category'=>'Slots','description'=>'A golden reel adventure.','tag'=>'ROADMAP','art'=>'gold','symbol'=>'♛'],
            ['title'=>'Nile Rush','slug'=>'nile-rush','provider'=>'Blue Nile','category'=>'Slots','description'=>'A flowing multiplier concept.','tag'=>'ROADMAP','art'=>'nile','symbol'=>'≈'],
            ['title'=>'Coffee Coins','slug'=>'coffee-coins','provider'=>'Lucy Originals','category'=>'Slots','description'=>'Warm reels and bright rewards.','tag'=>'ROADMAP','art'=>'coffee','symbol'=>'◆'],
            ['title'=>'Axum Treasures','slug'=>'axum-treasures','provider'=>'Sheba Arcade','category'=>'Slots','description'=>'Discover ancient symbols.','tag'=>'10 LINES','art'=>'axum','symbol'=>'✦'],
            ['title'=>'Rift Wilds','slug'=>'rift-wilds','provider'=>'Rift Labs','category'=>'Slots','description'=>'Wild symbols from the rift.','tag'=>'MEGAWAYS','art'=>'rift','symbol'=>'W'],
            ['title'=>'Blue Nile 7s','slug'=>'blue-nile-7s','provider'=>'Blue Nile','category'=>'Slots','description'=>'A clean classic seven concept.','tag'=>'CLASSIC','art'=>'sevens','symbol'=>'7'],
            ['title'=>'Lion Pride','slug'=>'lion-pride','provider'=>'Sheba Arcade','category'=>'Slots','description'=>'A royal jackpot concept.','tag'=>'JACKPOT','art'=>'lion','symbol'=>'♞'],
            ['title'=>'Orbit Bonus','slug'=>'orbit-bonus','provider'=>'Lucy Originals','category'=>'Slots','description'=>'Cosmic bonus rounds.','tag'=>'BONUS','art'=>'orbit','symbol'=>'◎'],
        ];

        $records = collect($games)->mapWithKeys(function ($data, $index) use ($providers, $categories) {
            $game = Game::query()->updateOrCreate(['slug' => $data['slug']], [
                'provider_id' => $providers[$data['provider']]->id,
                'category_id' => $categories[$data['category']]->id,
                'title' => $data['title'], 'description' => $data['description'], 'tag' => $data['tag'], 'art' => $data['art'], 'symbol' => $data['symbol'],
                'agent' => $data['agent'] ?? null, 'playable' => $data['playable'] ?? false, 'top_game' => $data['top_game'] ?? false,
                'crash' => false, 'active' => true, 'sort_order' => $index,
            ]);
            return [$game->slug => $game];
        });

        $lobbies = [
            ['name'=>'Trending','slug'=>'trending','description'=>'Player favorites and playable originals','sort_order'=>0,'games'=>['lucky-dice','coin-flip','high-low','sky-dash','sheba-gold']],
            ['name'=>'Playable agents','slug'=>'playable-agents','description'=>'Real local games available now','sort_order'=>1,'games'=>['lucky-dice','coin-flip','high-low']],
            ['name'=>'Slots','slug'=>'slots','description'=>'Bright reels and local concepts','sort_order'=>2,'games'=>['sheba-gold','nile-rush','coffee-coins','axum-treasures','rift-wilds','blue-nile-7s','lion-pride','orbit-bonus']],
        ];

        foreach ($lobbies as $data) {
            $lobby = GameLobby::query()->updateOrCreate(['slug' => $data['slug']], collect($data)->except('games')->all() + ['active' => true]);
            $lobby->games()->sync(collect($data['games'])->mapWithKeys(fn ($slug, $order) => [$records[$slug]->id => ['sort_order' => $order]])->all());
        }
    }
}
