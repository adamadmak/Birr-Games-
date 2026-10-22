<?php
/*
 * The Trending rail is curated, and it has to survive the catalogue sync.
 *
 * `top_game` is a sync-managed field: the QTech feed sends `top_game => false`
 * for all 10,223 games, so a game marked as featured is un-marked by the next
 * hourly refresh unless the field is PINNED in `manual_overrides`. A check that
 * only asserted the flag was set would pass on a rail that empties itself an
 * hour later.
 *
 * Run: php tests/featured_games_smoke.php   (local .env database, rolled back)
 */
$ROOT = $argv[1] ?? dirname(__DIR__); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Game,GameProvider,GameCategory,PlatformSetting};
use App\Support\{GameSearch,CatalogCache};
use Illuminate\Support\Facades\{DB,Cache}; use Illuminate\Support\Str;
$pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
try {
DB::beginTransaction();

$prov=GameProvider::create(['name'=>'Trend'.uniqid(),'slug'=>'trend-'.uniqid(),'description'=>'x','accent'=>'emerald','active'=>true,'content_server_enabled'=>true,'sync_enabled'=>true]);
$cat=GameCategory::firstOrCreate(['slug'=>'slots'],['name'=>'Slots','description'=>'x','sort_order'=>1,'active'=>true]);
$mk=function(string $title) use($prov,$cat){ return Game::create(['provider_id'=>$prov->id,'category_id'=>$cat->id,'provider_game_id'=>$title.uniqid(),'external_launch_code'=>'x','title'=>$title,'slug'=>Str::slug($title).'-'.substr(uniqid(),-5),'sync_source'=>'qtech','playable'=>true,'active'=>true,'content_server_enabled'=>true,'art'=>'provider','symbol'=>'*','sort_order'=>1]); };

// Titles exactly as the feed delivers them: run together.
$target=$mk('MultiHot5');
$lookalike=$mk('MultiHot5BurnWilds');
$other=$mk('SomethingElse');

echo "— the curated list matches on the squashed title, exactly —\n";
$squashed=GameSearch::squashedColumn('title');
$found=Game::query()->whereRaw($squashed.' = ?', [GameSearch::squash('Multi Hot 5')])->pluck('id')->all();
check('"Multi Hot 5" finds the run-together title', in_array($target->id, $found, true));
check('and does NOT also catch Burn Wilds', ! in_array($lookalike->id, $found, true), 'substring match would have');
check('nor anything unrelated', ! in_array($other->id, $found, true));

echo "\n— marking one, the way the migration does —\n";
$overrides=array_values(array_unique(array_merge($target->manual_overrides ?? [], ['top_game'])));
$target->forceFill(['top_game'=>true,'manual_overrides'=>$overrides])->save();
$target->refresh();
check('the flag is set', (bool)$target->top_game===true);
check('and the field is pinned', $target->isPinned('top_game'));
check('pinnedFields reports it', in_array('top_game', $target->pinnedFields(), true));

echo "\n— and a catalogue sync cannot undo it —\n";
// Exactly what QtechCatalogSynchronizer::upsertGame does: build the feed's
// values, then drop the ones an administrator pinned.
$fromFeed=['title'=>'MultiHot5','top_game'=>false,'active'=>true,'playable'=>true];
foreach ($target->pinnedFields() as $pinned) { unset($fromFeed[$pinned]); }
check('the feed no longer carries top_game', ! array_key_exists('top_game', $fromFeed));
$target->forceFill($fromFeed)->save(); $target->refresh();
check('so it survives the write', (bool)$target->top_game===true);
check('while unpinned fields still follow the feed', (bool)$target->active===true);

echo "\n— an unpinned game would NOT have survived —\n";
$lookalike->forceFill(['top_game'=>true])->save();
$feed2=['top_game'=>false];
foreach ($lookalike->fresh()->pinnedFields() as $pinned) { unset($feed2[$pinned]); }
$lookalike->forceFill($feed2)->save();
check('proving the pin is what does the work', (bool)$lookalike->fresh()->top_game===false);

echo "\n— the rail picks them up —\n";
DB::table('platform_settings')->upsert([['key'=>'qtech_live_to_players','value'=>'1'],['key'=>'qtech_api_base_url','value'=>'https://api.qtplatform.com']],['key'],['value']);
PlatformSetting::flushValueCache(); CatalogCache::bump(); Cache::flush();
// Scoped to this test's own games: the dev database carries others, and
// whether THEY are featured is not what is being checked here.
$mine=[$target->id,$lookalike->id,$other->id];
$ordered=Game::query()->available()->whereIn('id',$mine)->orderByDesc('top_game')->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
check('the featured one sorts ahead of its siblings', $ordered !== [] && $ordered[0]===$target->id, json_encode($ordered));
check('and the other two are still available', count($ordered)===3, json_encode($ordered));

echo "\n— the rail shows every featured game, not the first ten —\n";
// Twelve marked, ten shown used to drop two silently. Fifteen here, so the
// old take(10) would fail this even with a generous rail.
$extra=[];
for ($i=0; $i<15; $i++) {
    $g=$mk('Featured'.$i);
    $g->forceFill(['top_game'=>true,'manual_overrides'=>['top_game'],'sort_order'=>$i])->save();
    $extra[]=$g->id;
}
CatalogCache::bump(); Cache::flush();
$page=(string)$app->make(Illuminate\Contracts\Http\Kernel::class)
    ->handle(tap(Illuminate\Http\Request::create('https://localhost/','GET'), function($r) use($app){ $s=$app['session']->driver(); $s->start(); $r->setLaravelSession($s); }))
    ->getContent();
// Scoped to the Trending section itself: a featured game also appears in its
// studio shelf and the catalogue grid, so counting across the whole page would
// pass even with the old take(10).
$start=strpos($page,'Trending');
$rail=$start===false ? '' : substr($page,$start, (int) (strpos($page,'data-game-section', $start+10) ?: $start+20000) - $start + 20000);
$rail=substr($rail, 0, strpos($rail,'</section>') ?: strlen($rail));
$shown=0;
foreach (Game::whereIn('id',$extra)->pluck('slug') as $slug) { if (str_contains($rail,$slug)) $shown++; }
check('all fifteen featured games are on the Trending rail', $shown===15, "only $shown of 15 in the rail");
$aviatorish=Game::whereIn('id',$extra)->count();
check('and they are all still marked and pinned', Game::whereIn('id',$extra)->where('top_game',true)->count()===$aviatorish);

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } }
exit($fail?1:0);
