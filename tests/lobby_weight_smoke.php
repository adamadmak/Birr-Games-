<?php
/*
 * What the lobby actually costs a player to load.
 *
 * The catalogue went from a few hundred games to ten thousand when QTech went
 * live, and two things about the lobby did not scale with it. It rendered one
 * shelf per studio, which at 119 studios meant 1,135 tiles and 1.5 MB of
 * markup; and nothing in front of the app compresses anything, so all of it
 * travelled uncompressed to a market that is almost entirely mobile.
 *
 * Neither is visible from the server's own timings — the queries were always
 * fast. This measures the thing the player pays for: bytes on the wire.
 *
 * Run: php tests/lobby_weight_smoke.php   (local .env database, rolled back)
 */
$ROOT = $argv[1] ?? dirname(__DIR__); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Game,GameCategory,PlatformSetting};
use App\Support\CatalogCache;
use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB,Cache,Auth};
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
try {
DB::beginTransaction();

// A catalogue the size of the real one: 100 studios, ~10,000 games.
$cat=GameCategory::firstOrCreate(['slug'=>'slots'],['name'=>'Slots','description'=>'x','sort_order'=>1,'active'=>true]);
$now=now();
for($p=0;$p<100;$p++){
    $pid=DB::table('game_providers')->insertGetId(['name'=>'Studio '.str_pad((string)$p,3,'0',STR_PAD_LEFT),'slug'=>'studio-'.$p.'-'.uniqid(),'description'=>'x','accent'=>'emerald','active'=>1,'content_server_enabled'=>1,'sync_enabled'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $rows=[];
    for($g=0;$g<100;$g++){
        $rows[]=['provider_id'=>$pid,'category_id'=>$cat->id,'provider_game_id'=>"p{$p}g{$g}",'external_launch_code'=>"p{$p}g{$g}",'title'=>"Game $p-$g",'slug'=>"game-$p-$g-".substr(uniqid(),-6),'description'=>'x','tag'=>'','art'=>'provider','symbol'=>'*','image_url'=>"https://cdn.example.com/$p/$g.png",'sync_source'=>'qtech','playable'=>1,'top_game'=>0,'free_bet_supported'=>0,'crash'=>0,'content_server_enabled'=>1,'active'=>1,'sort_order'=>$g,'created_at'=>$now,'updated_at'=>$now];
    }
    foreach(array_chunk($rows,200) as $c) DB::table('games')->insert($c);
}
DB::table('platform_settings')->upsert([['key'=>'qtech_live_to_players','value'=>'1']],['key'],['value']);
PlatformSetting::flushValueCache(); CatalogCache::bump(); Cache::flush();
config(['services.qtech.api_base_url'=>'https://api.qtplatform.com']);
$available=Game::query()->available()->count();
check('the catalogue really is at scale', $available>=10000, (string)$available);

$fetch=function(array $headers) use($app,$http){
    Auth::logout(); $s=$app['session']->driver(); $s->start();
    $r=Request::create('https://localhost/','GET',[],[],[],$headers); $r->setLaravelSession($s);
    return $http->handle($r);
};

echo "\n— the front page is bounded, not one shelf per studio —\n";
$plain=$fetch([]);
$html=(string)$plain->getContent();
$shelves=preg_match_all('/provider-studio-/', $html);
$tiles=preg_match_all('/data-game-card/', $html);
$options=preg_match_all('/<option/', $html);
check('page renders', $plain->getStatusCode()===200);
check('shelves are capped', $shelves>0 && $shelves<=12, "shelves=$shelves");
check('tiles stay in the low hundreds', $tiles>0 && $tiles<=200, "tiles=$tiles");
check('but every studio is still in the filter', $options>=100, "options=$options");
check('uncompressed page is under 400KB', strlen($html)<400*1024, round(strlen($html)/1024).'KB');

echo "\n— and it travels compressed —\n";
$gz=$fetch(['HTTP_ACCEPT_ENCODING'=>'gzip, deflate, br']);
$body=(string)$gz->getContent();
check('gzip is applied when asked for', $gz->headers->get('Content-Encoding')==='gzip', (string)$gz->headers->get('Content-Encoding'));
check('Vary announces it on both', str_contains((string)$plain->headers->get('Vary'),'Accept-Encoding') && str_contains((string)$gz->headers->get('Vary'),'Accept-Encoding'));
check('Content-Length matches the compressed body', (int)$gz->headers->get('Content-Length')===strlen($body), $gz->headers->get('Content-Length').' vs '.strlen($body));
check('at least 5x smaller on the wire', strlen($body)*5 < strlen($html), round(strlen($html)/1024).'KB -> '.round(strlen($body)/1024).'KB');
check('a client that cannot gunzip still gets plain HTML', $plain->headers->get('Content-Encoding')===null && str_contains($html,'<html'));
check('the compressed body really is gzip', substr($body,0,2)==="\x1f\x8b");
check('and it decompresses to the same page', gzdecode($body)===$html);

echo "\n— and the art is asked for at the size we draw it —\n";
// QTech's default is a full-resolution PNG: 220-530 KB measured across the
// live catalogue, for a tile drawn around 300px wide. Their service takes
// width and format, which brings the same image to 12-24 KB.
$art=function(?string $url){ $g=new Game(); $g->image_url=$url; $g->image_path=null; return $g->image_source; };
$qt='https://client.qtlauncher.com/images/?id=MNC-x_en_US&type=logo-square&version=1';
check('a QTech image is resized and re-encoded', $art($qt)===$qt.'&width=300&format=webp', (string)$art($qt));
check('one that already carries a width is left alone', $art($qt.'&width=64')===$qt.'&width=64');
check('another provider\'s art is untouched', $art('https://bigbangcasino.bet/ico/x.webp?v=1')==='https://bigbangcasino.bet/ico/x.webp?v=1');
check('a game with no art stays null', $art(null)===null);
$g=new Game(); $g->image_path='promo/x.png'; $g->image_url=$qt;
check('an uploaded image still wins over the feed', str_contains((string)$g->image_source,'promo/x.png'));

printf("\n  page on the wire: %dKB uncompressed -> %dKB gzipped, %d shelves, %d tiles, %s games\n",
    round(strlen($html)/1024), round(strlen($body)/1024), $shelves, $tiles, number_format($available));

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } }
exit($fail?1:0);
