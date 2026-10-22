<?php
/*
 * BigBang is retired. This is what "retired" has to mean.
 *
 * Not "switched off in the admin" — that does not hold. Both catalogue syncs
 * write `active => true` back over any such change within the hour, and
 * provider rows are shared with QTech, so the providers come back too. The
 * retirement is therefore a gate in the query, and these checks exist to prove
 * the gate holds no matter what the rows say.
 *
 * The other half matters just as much: retiring is not the same as settling.
 * A bet already placed still has to pay out, so the wallet endpoints must stay
 * reachable. A test that only proved BigBang was gone would be happy with a
 * change that stranded real money.
 *
 * Run: php tests/bigbang_retired_smoke.php   (local .env database, rolled back)
 */
$ROOT = $argv[1] ?? dirname(__DIR__); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,Game,GameProvider,GameCategory,BigBangSession,PlatformSetting};
use App\Support\{BigBang,CatalogCache};
use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB,Cache,Auth,Artisan}; use Illuminate\Support\Str;
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
$TOKEN='test-callback-token-at-least-32-chars-long';
config([
    'services.bigbang.callback_token'=>$TOKEN,
    'services.bigbang.currency'=>'ETB',
    'services.bigbang.mode'=>'live',
    'services.qtech.api_base_url'=>'https://api.qtplatform.com',
]);
try {
DB::beginTransaction();

$prov=GameProvider::create(['name'=>'Shared'.uniqid(),'slug'=>'shared-'.uniqid(),'description'=>'x','accent'=>'emerald','active'=>true,'content_server_enabled'=>true,'sync_enabled'=>true]);
$cat=GameCategory::firstOrCreate(['slug'=>'slots'],['name'=>'Slots','description'=>'x','sort_order'=>1,'active'=>true]);
$mk=fn(string $src,string $tag)=>Game::create(['provider_id'=>$prov->id,'category_id'=>$cat->id,'provider_game_id'=>$tag.uniqid(),'external_launch_code'=>$tag,'title'=>'Retire '.$tag,'slug'=>$tag.'-'.uniqid(),'sync_source'=>$src,'playable'=>true,'active'=>true,'content_server_enabled'=>true,'art'=>'provider','symbol'=>'*','sort_order'=>1]);
// Both rows fully active, exactly as a sync would leave them.
$bb=$mk('bigbang','bb'); $qt=$mk('qtech','qt');
DB::table('platform_settings')->upsert([['key'=>'qtech_live_to_players','value'=>'1']],['key'],['value']);
PlatformSetting::flushValueCache(); CatalogCache::bump(); Cache::flush();

$player=User::forceCreate(['name'=>'P','username'=>'p'.uniqid(),'password'=>'x','role'=>'player','credits'=>500,'withdrawable_balance'=>500,'active'=>true]);
$sess=function(User $u) use($app){ Auth::login($u); $s=$app['session']->driver(); $s->start(); return $s; };
$anon=function($path,$method='GET',$params=[]) use($app,$http){ Auth::logout(); $s=$app['session']->driver(); $s->start(); $r=Request::create("https://localhost$path",$method,$params); $r->setLaravelSession($s); return $http->handle($r); };
$as=function(User $u,$path,$method='GET',$params=[]) use($http,$sess){ $s=$sess($u); $r=Request::create("https://localhost$path",$method,$params+['_token'=>$s->token()]); $r->setLaravelSession($s); return $http->handle($r); };

echo "— retired by default, with the rows left fully active —\n";
check('the row itself is still active and playable', $bb->active && $bb->playable);
check('BigBang reads as retired with no setting stored', BigBang::retired()===true);
check('the game is excluded from available()', ! Game::query()->available()->whereKey($bb->id)->exists());
check('isAvailable() says no', $bb->fresh()->isAvailable()===false);
check('nobody can reach it by URL, not even the QTech fixture', $bb->fresh()->isVisibleTo(null)===false);
check('QTech games are untouched', Game::query()->available()->whereKey($qt->id)->exists() && $qt->fresh()->isAvailable()===true);

echo "\n— and it is gone from every player-facing surface —\n";
$home=(string)$anon('/')->getContent();
check('not on the lobby', ! str_contains($home,$bb->slug));
check('QTech game still on the lobby', str_contains($home,$qt->slug));
check('its game page 404s', $anon("/games/{$bb->slug}")->getStatusCode()===404);
check('its GET launch route 404s too', $anon("/games/{$bb->slug}/provider/launch")->getStatusCode()===404);
check('a logged-in player cannot launch it', $as($player,"/games/{$bb->slug}/provider/launch",'POST')->getStatusCode()===404);
check('the QTech game still launches for a player', in_array($as($player,"/games/{$qt->slug}/provider/launch",'POST')->getStatusCode(),[200,302],true));

echo "\n— the catalogue cannot bring it back —\n";
// BigBang's endpoint takes the token as a query parameter, not a bearer header.
$syncToken=trim((string) config('services.bigbang.sync_token'));
$r=Request::create('https://localhost/api/integrations/bigbang/sync-catalog?token='.$syncToken,'POST');
check('the timer endpoint refuses with 410 Gone', $http->handle($r)->getStatusCode()===410);
$admin=User::forceCreate(['name'=>'A','username'=>'a'.uniqid(),'password'=>'x','role'=>'admin','active'=>true]);
$res=$as($admin,'/admin/game-engine/sync','POST');
check('the admin Sync button refuses', $res->getStatusCode()===302 && str_contains(json_encode(session('errors')?->getBag('default')?->all() ?? []),'retired'), (string)$res->getStatusCode());
$code=Artisan::call('bigbang:sync-catalog');
check('the console command exits without syncing', $code===0 && str_contains(Artisan::output(),'retired'), Artisan::output());

echo "\n— but a round already in flight can still settle —\n";
// Retiring is not settling. These endpoints stay reachable until nothing is
// in flight; a wrong signature must be the only thing standing in the way.
$wallet=function(array $body) use($http,$TOKEN){
    $r=Request::create('https://localhost/api/integrations/bigbang/balance-change?token='.$TOKEN,'POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($body));
    return $http->handle($r);
};
$res=$wallet(['username'=>'player_'.$player->id,'transaction_id'=>'t'.uniqid(),'amount'=>-10]);
check('the wallet endpoint is still alive', ! in_array($res->getStatusCode(),[404,410,503],true), 'status '.$res->getStatusCode());
check('and only the signature refuses it', $res->getStatusCode()===401, (string)$res->getContent());

echo "\n— the balance route no longer answers for anyone who asks —\n";
$balance=fn(User $u)=>$http->handle(Request::create('https://localhost/api/integrations/bigbang/user-data','GET',['username'=>'player_'.$u->id,'token'=>$TOKEN]));
check('a player with no open session is not disclosed', $balance($player)->getStatusCode()===404);
BigBangSession::create(['user_id'=>$player->id,'game_id'=>$bb->id,'external_session_id'=>Str::random(40),'status'=>'active','mode'=>'real','currency'=>'ETB','expires_at'=>now()->addHours(6),'last_activity_at'=>now()]);
$res=$balance($player);
check('a player mid-round still gets their balance', $res->getStatusCode()===200, (string)$res->getContent());
check('a wrong token is still refused', $http->handle(Request::create('https://localhost/api/integrations/bigbang/user-data','GET',['username'=>'player_'.$player->id,'token'=>'nope']))->getStatusCode()===401);

echo "\n— and an operator can undo it without a deploy —\n";
DB::table('platform_settings')->upsert([['key'=>'bigbang_retired','value'=>'0']],['key'],['value']);
PlatformSetting::flushValueCache(); CatalogCache::bump(); Cache::flush();
check('BigBang reads as live again', BigBang::retired()===false);
check('and the game comes straight back', Game::query()->available()->whereKey($bb->id)->exists());

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } }
exit($fail?1:0);
