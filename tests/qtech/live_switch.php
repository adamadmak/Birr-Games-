<?php
$ROOT = $argv[1] ?? dirname(__DIR__, 2); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,Game,GameProvider,GameCategory,QtechSession,PlatformSetting}; use App\Services\QtechTestCredentials; use App\Support\Qtech;
use Illuminate\Http\Request; use Illuminate\Support\Facades\{Http,Cache,DB,Auth};
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
config(['services.qtech.api_username'=>'u','services.qtech.api_password'=>'p','services.qtech.pass_key'=>'k','services.qtech.currency'=>'ETB']);
Http::fake(function($r){ $u=$r->url(); if(str_contains($u,'/v1/auth/token')) return Http::response(['access_token'=>'t'],200); if(str_contains($u,'/launch-url')) return Http::response(['url'=>'https://gl.qtplatform.com/x'],200); return Http::response([],404); });
try {
DB::beginTransaction();
$prov=GameProvider::create(['name'=>'QT','slug'=>'qt'.uniqid(),'description'=>'x','accent'=>'emerald','active'=>true,'content_server_enabled'=>true,'sync_enabled'=>true]);
$cat=GameCategory::firstOrCreate(['slug'=>'slots'],['name'=>'Slots','description'=>'x','sort_order'=>1,'active'=>true]);
$qt=Game::create(['provider_id'=>$prov->id,'category_id'=>$cat->id,'provider_game_id'=>'TK-live','external_launch_code'=>'TK-live','title'=>'Live Test','slug'=>'live-test-'.uniqid(),'sync_source'=>'qtech','playable'=>true,'active'=>true,'content_server_enabled'=>true,'art'=>'provider','symbol'=>'◆','sort_order'=>1]);
app(QtechTestCredentials::class)->issue(); $fixture=User::where('username','qtech_test')->first();
$normal=User::forceCreate(['name'=>'R','username'=>'r'.uniqid(),'password'=>'x','role'=>'player','credits'=>500,'withdrawable_balance'=>500,'active'=>true]);
$setLive=function(bool $on){ DB::table('platform_settings')->upsert([['key'=>'qtech_live_to_players','value'=>$on?'1':'0']],['key'],['value']); PlatformSetting::flushValueCache(); \App\Support\CatalogCache::bump(); Cache::flush(); };
$host=function(string $h){ config(['services.qtech.api_base_url'=>$h]); PlatformSetting::flushValueCache(); \App\Support\CatalogCache::bump(); Cache::flush(); };
$sess=function(User $u) use($app){ Auth::login($u); $s=$app['session']->driver(); $s->start(); return $s; };
$get=function($path,$s) use($http){ $r=Request::create("https://localhost$path",'GET'); $r->setLaravelSession($s); return $http->handle($r); };
$launch=function($s) use($http,$qt){ $r=Request::create("https://localhost/games/{$qt->slug}/provider/launch",'POST',['_token'=>$s->token()]); $r->setLaravelSession($s); return $http->handle($r); };

echo "— PRODUCTION host, switch OFF (the default): still hidden from players —\n";
$host('https://api.qtplatform.com'); DB::table('platform_settings')->where('key','qtech_live_to_players')->delete(); PlatformSetting::flushValueCache(); Cache::flush();
check('default is off', Qtech::liveToPlayers()===false);
check('held from players', Qtech::heldFromPlayers()===true);
Auth::logout(); $sn=$sess($normal);
check('lobby hides the game', !str_contains((string)$get('/',$sn)->getContent(),$qt->slug));
check('direct page 404 for a real player', $get("/games/{$qt->slug}",$sn)->getStatusCode()===404);
$res=$launch($sn); check('launch refused with "not open to players yet"', $res->getStatusCode()===302 && str_contains((string)$sn->get('errors')?->first('provider'),'not open to players'), (string)$sn->get('errors')?->first('provider'));
Auth::logout(); $sf=$sess($fixture);
check('fixture player can still open the page on production host', $get("/games/{$qt->slug}",$sf)->getStatusCode()===200);
check('fixture player can launch', $launch($sf)->getStatusCode()===200);

echo "— PRODUCTION host, switch ON: live —\n";
$setLive(true);
check('heldFromPlayers false', Qtech::heldFromPlayers()===false);
Auth::logout(); $sn=$sess($normal);
check('lobby shows the game', str_contains((string)$get('/',$sn)->getContent(),$qt->slug));
check('real player launches (200)', $launch($sn)->getStatusCode()===200);

echo "— INTEGRATION host, switch ON: STILL hidden (integration games never show) —\n";
$host('https://api-int.qtplatform.com');
check('heldFromPlayers true despite the switch', Qtech::heldFromPlayers()===true);
Auth::logout(); $sn=$sess($normal);
check('direct page 404 for a real player', $get("/games/{$qt->slug}",$sn)->getStatusCode()===404);

echo "— the admin checkbox round-trips, including turning it OFF —\n";
$host('https://api.qtplatform.com');
$admin=User::forceCreate(['name'=>'A','username'=>'a'.uniqid(),'password'=>'x','role'=>'admin','active'=>true]); Auth::logout(); $sa=$sess($admin);
$sys=['max_payout'=>1000000,'default_theme'=>'dark','min_deposit_amount'=>50,'max_deposit_amount'=>100000,'min_withdraw_amount'=>30,'max_withdraw_amount'=>30000,'max_transfer_amount'=>50000,'min_transfer_amount'=>10,'daily_max_withdraw'=>100000,'daily_max_transfer'=>100000,'daily_max_deposit'=>250000,'tournament_prize_first'=>500,'tournament_prize_second'=>250,'tournament_prize_third'=>100,'tournament_prize_other'=>50,'tournament_paid_places'=>10];
$put=function($d) use($http,$sa){ $r=Request::create('https://localhost/admin/settings','PUT',$d+['_token'=>$sa->token(),'section'=>'system']); $r->setLaravelSession($sa); return $http->handle($r); };
$put($sys+['qtech_live_to_players'=>'1']); PlatformSetting::flushValueCache(); check('ticked -> live', Qtech::liveToPlayers()===true);
$put($sys); PlatformSetting::flushValueCache(); check('unticked (absent) -> off', Qtech::liveToPlayers()===false);
$page=(string)$get('/admin/settings?tab=system',$sa)->getContent();
check('checkbox rendered with its state text', str_contains($page,'qtech_live_to_players') && str_contains($page,'Only the test player can open QTech games'));
echo "\n$pass passed, $fail failed\n";
// Laravel's global exception handler was installed by the bootstrap() call at the top
// of this file. For a console process it RENDERS an uncaught throwable and then returns
// rather than exiting non-zero — so a crash used to leave PHP exiting 0, the summary
// above unprinted, and the README's `|| exit 1` gate unable to fire. The transaction
// starts inside the try for the same reason: a database that will not open must fail
// this script, not sail past it.
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } }
exit($fail?1:0);
