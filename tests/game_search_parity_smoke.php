<?php
/*
 * The admin has to find a game by the same name the player found it by.
 *
 * Feed titles arrive run together — "BookOfKemet" — and people search the way
 * they speak, "book of kemet". The lobby was taught to strip separators from
 * both sides of the comparison; the admin's Game Engine was not, because the
 * fix lived privately inside LobbyController. So an operator could see a game
 * on the live site, search for it in the admin to make it a Top Game, and be
 * told it does not exist.
 *
 * These checks run the SAME queries the two surfaces run, so they fail if the
 * two ever drift apart again.
 *
 * Run: php tests/game_search_parity_smoke.php   (local .env database, rolled back)
 */
$ROOT = $argv[1] ?? dirname(__DIR__); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,Game,GameProvider,GameCategory,PlatformSetting};
use App\Support\{GameSearch,CatalogCache};
use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB,Cache,Auth};
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
config(['services.qtech.api_base_url'=>'https://api.qtplatform.com']);
try {
DB::beginTransaction();

$prov=GameProvider::create(['name'=>'Pragmatic Play Casino','slug'=>'ppc-'.uniqid(),'description'=>'x','accent'=>'emerald','active'=>true,'content_server_enabled'=>true,'sync_enabled'=>true]);
$cat=GameCategory::firstOrCreate(['slug'=>'slots'],['name'=>'Slots','description'=>'x','sort_order'=>1,'active'=>true]);
// Exactly the shape QTech's feed delivers: one run-together word.
$game=Game::create(['provider_id'=>$prov->id,'category_id'=>$cat->id,'provider_game_id'=>'END-bookofkemet','external_launch_code'=>'END-bookofkemet','title'=>'BookOfKemet','slug'=>'bookofkemet-'.substr(uniqid(),-6),'sync_source'=>'qtech','playable'=>true,'active'=>true,'content_server_enabled'=>true,'art'=>'provider','symbol'=>'*','sort_order'=>5]);
// A decoy with a lower sort_order takes the hero banner's "Play X" button, which
// is chosen independently of the search. Without it the target's slug is on the
// page whatever was searched for, and every check below passes for free.
Game::create(['provider_id'=>$prov->id,'category_id'=>$cat->id,'provider_game_id'=>'END-decoy','external_launch_code'=>'END-decoy','title'=>'Decoy Featured','slug'=>'decoy-'.substr(uniqid(),-6),'sync_source'=>'qtech','playable'=>true,'active'=>true,'content_server_enabled'=>true,'art'=>'provider','symbol'=>'*','sort_order'=>0]);
DB::table('platform_settings')->upsert([['key'=>'qtech_live_to_players','value'=>'1']],['key'],['value']);
PlatformSetting::flushValueCache(); CatalogCache::bump(); Cache::flush();

$admin=User::forceCreate(['name'=>'A','username'=>'a'.uniqid(),'password'=>'x','role'=>'admin','active'=>true]);
Auth::login($admin); $sa=$app['session']->driver(); $sa->start();
$engine=function(string $term) use($http,$sa,$game){
    $r=Request::create('https://localhost/admin/game-engine','GET',['page'=>'game','search'=>$term]);
    $r->setLaravelSession($sa);
    return str_contains((string)$http->handle($r)->getContent(), $game->slug);
};
$lobby=function(string $term) use($app,$http,$game){
    Auth::logout(); $s=$app['session']->driver(); $s->start();
    $r=Request::create('https://localhost/','GET',['q'=>$term]); $r->setLaravelSession($s);
    $found=str_contains((string)$http->handle($r)->getContent(), $game->slug);
    Auth::login(User::where('role','admin')->latest('id')->first());
    return $found;
};

echo "— the name a person actually types —\n";
foreach (['book of kemet','Book Of Kemet','BookOfKemet','book-of-kemet','kemet','BOOK OF KEMET'] as $term) {
    $a=$engine($term); $l=$lobby($term);
    check(sprintf('"%s" — admin %s / lobby %s', $term, $a?'finds':'MISSES', $l?'finds':'MISSES'), $a && $l);
}

echo "\n— and by studio, which the admin could never do —\n";
check('"pragmatic" finds it in the admin', $engine('pragmatic'));
check('"pragmatic play" finds it in the admin', $engine('pragmatic play'));
check('"pragmaticplay" finds it in the admin', $engine('pragmaticplay'));

echo "\n— without matching things it should not —\n";
check('an unrelated term finds nothing in the admin', ! $engine('zzzznotagame'));
check('an unrelated term finds nothing in the lobby', ! $lobby('zzzznotagame'));

echo "\n— the two surfaces agree, term for term —\n";
$drift=[];
foreach (['book of kemet','kemet','BookOf','pragmatic','of kemet','zzzznotagame','book_of_kemet'] as $term) {
    if ($engine($term) !== $lobby($term)) { $drift[]=$term; }
}
check('no term is found by one surface and missed by the other', $drift===[], implode(', ', $drift));

echo "\n— a filter that hides a match says so, instead of \"no records\" —\n";
// Jet X is a crash game and its title in the feed is literally "jetx". An
// operator searching "jet x" with Is Crash = No left over from an earlier
// filter was told no records matched, with nothing to say the filter was the
// reason. The search was right; the message was not.
$crashGame=Game::create(['provider_id'=>$prov->id,'category_id'=>$cat->id,'provider_game_id'=>'SMS-jetx','external_launch_code'=>'SMS-jetx','title'=>'jetx','slug'=>'qtech-jetx-'.substr(uniqid(),-5),'sync_source'=>'qtech','playable'=>true,'active'=>true,'content_server_enabled'=>true,'crash'=>true,'art'=>'provider','symbol'=>'*','sort_order'=>9]);
$engineRaw=function(array $params) use($http,$sa){
    $r=Request::create('https://localhost/admin/game-engine','GET',$params+['page'=>'game']);
    $r->setLaravelSession($sa);
    return (string)$http->handle($r)->getContent();
};
$plain=$engineRaw(['search'=>'jet x']);
check('the crash game is found with no filters', str_contains($plain, $crashGame->slug));
// Exactly the query string the admin produces with all four selects on "No".
$filtered=$engineRaw(['search'=>'jet x','crash'=>'0','disabled'=>'0','top_game'=>'0','content_disabled'=>'0']);
check('Is Crash = No does hide it', ! str_contains($filtered, $crashGame->slug));
check('and the empty state names the filter', str_contains($filtered, 'Is Crash = No'), 'message did not name it');
check('it quotes what was searched for', str_contains($filtered, 'jet x'));
check('and offers a way to clear them', str_contains($filtered, 'Clear the filters'));
$noMatch=$engineRaw(['search'=>'zzzznotagame']);
check('an honest miss does not blame filters', str_contains($noMatch,'zzzznotagame') && ! str_contains($noMatch,'Is Crash = '));

echo "\n— the helper itself —\n";
check('squash strips separators and case', GameSearch::squash('Book Of  Kemet-2')==='bookofkemet2');
check('an empty term leaves a query untouched', Game::query()->whereKey($game->id)->tap(fn($q)=>GameSearch::apply($q,'   '))->count()===1);

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } }
exit($fail?1:0);
