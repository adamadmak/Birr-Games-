<?php
/*
 * The surfaces the go-live switch actually has to move, end to end.
 *
 * live_switch.php proves the gate decides correctly. This proves the decision
 * REACHES the player, which is a different thing and is where the gaps were:
 * the switch is flipped by an admin form that fires no model events, the lobby
 * serves a shared cached snapshot, and the game itself is rendered inside an
 * iframe governed by our own Content-Security-Policy. Each of those three sat
 * between a correct decision and what a player sees.
 */
$ROOT = $argv[1] ?? dirname(__DIR__, 2); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,Game,GameProvider,GameCategory,PlatformSetting}; use App\Support\{Qtech,CatalogCache};
use Illuminate\Http\Request; use Illuminate\Support\Facades\{Http,Cache,DB,Auth};
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
config(['services.qtech.api_username'=>'u','services.qtech.api_password'=>'p','services.qtech.pass_key'=>'k','services.qtech.currency'=>'ETB']);
Http::fake(function($r){ $u=$r->url();
    if(str_contains($u,'/v1/auth/token')) return Http::response(['access_token'=>'t','expires_in'=>3600],200);
    // Shaped like the real thing: QTech hand back their own launcher, which is
    // NOT where the game ends up running.
    if(str_contains($u,'/launch-url')) return Http::response(['url'=>'https://client.qtlauncher.com/client/game-launcher.html#/?gameSession=abc'],200);
    return Http::response([],404);
});
try {
DB::beginTransaction();

$prov=GameProvider::create(['name'=>'QT'.uniqid(),'slug'=>'qt'.uniqid(),'description'=>'x','accent'=>'emerald','active'=>true,'content_server_enabled'=>true,'sync_enabled'=>true]);
$cat=GameCategory::firstOrCreate(['slug'=>'slots'],['name'=>'Slots','description'=>'x','sort_order'=>1,'active'=>true]);
$mk=fn(string $src,string $tag)=>Game::create(['provider_id'=>$prov->id,'category_id'=>$cat->id,'provider_game_id'=>$tag.uniqid(),'external_launch_code'=>$tag,'title'=>'Surface '.$tag,'slug'=>$tag.'-'.uniqid(),'sync_source'=>$src,'playable'=>true,'active'=>true,'content_server_enabled'=>true,'art'=>'provider','symbol'=>'◆','sort_order'=>1]);
// The control is a LOCAL game, not a BigBang one: BigBang is retired now,
// so it is no more visible than a gated QTech game and proves nothing.
$qt=$mk('qtech','qt'); $bb=$mk('local','local');

$anon=function($path) use($app,$http){ Auth::logout(); $s=$app['session']->driver(); $s->start(); $r=Request::create("https://localhost$path",'GET'); $r->setLaravelSession($s); return $http->handle($r); };
$sess=function(User $u) use($app){ Auth::login($u); $s=$app['session']->driver(); $s->start(); return $s; };
$get=function($path,$s) use($http){ $r=Request::create("https://localhost$path",'GET'); $r->setLaravelSession($s); return $http->handle($r); };

echo "— the launcher's frame is allowed by our own CSP —\n";
// QtechGameController returns a *.qtplatform.com / *.qtlauncher.com URL and the
// game page drops it into an iframe. If frame-src omits those hosts the browser
// refuses the frame and the player gets a blank stage with no server-side error,
// so nothing else in this file would notice.
$csp=(string)$anon('/')->headers->get('Content-Security-Policy');
preg_match('/frame-src ([^;]*)/', $csp, $m); $frame=$m[1] ?? '';
check('CSP sets frame-src', $frame!=='' , $csp);
foreach (Qtech::allowedLaunchHosts() as $host) {
    check("frame-src allows *.$host", str_contains($frame, '*.'.$host) && str_contains($frame, 'https://'.$host), $frame);
}
check('existing launchers kept', str_contains($frame,'bigbangcasino.bet') && str_contains($frame,'winhouse.bet') && str_contains($frame,'telegram.org'), $frame);

echo "\n— the GET launch route is not an existence oracle —\n";
// It exists so a guest bounced through login lands on the game rather than a 405.
// It must therefore answer exactly as the game page does: a hidden game is 404,
// not a 302 that confirms the slug is real.
DB::table('platform_settings')->where('key','qtech_live_to_players')->delete(); PlatformSetting::flushValueCache(); CatalogCache::bump();
config(['services.qtech.api_base_url'=>'https://api.qtplatform.com']);
check('hidden QTech game -> 404', $anon("/games/{$qt->slug}/provider/launch")->getStatusCode()===404);
check('same as its game page', $anon("/games/{$qt->slug}")->getStatusCode()===404);
check('unknown slug -> 404 too', $anon('/games/no-such-game-'.uniqid().'/provider/launch')->getStatusCode()===404);
check('visible game still redirects', $anon("/games/{$bb->slug}/provider/launch")->getStatusCode()===302);

echo "\n— the lobby's shared snapshots follow the switch, with no manual bump —\n";
$admin=User::forceCreate(['name'=>'A','username'=>'a'.uniqid(),'password'=>'x','role'=>'admin','active'=>true]);
$sys=['max_payout'=>1000000,'default_theme'=>'dark','min_deposit_amount'=>50,'max_deposit_amount'=>100000,'min_withdraw_amount'=>30,'max_withdraw_amount'=>30000,'max_transfer_amount'=>50000,'min_transfer_amount'=>10,'daily_max_withdraw'=>100000,'daily_max_transfer'=>100000,'daily_max_deposit'=>250000,'tournament_prize_first'=>500,'tournament_prize_second'=>250,'tournament_prize_third'=>100,'tournament_prize_other'=>50,'tournament_paid_places'=>10];
// Signs in for each save: the lobby fetches between them are anonymous, and an
// unauthenticated PUT would be bounced to login and save nothing — which would
// make every assertion below pass for the wrong reason.
$put=function($d) use($http,$sess,$admin){ $sa=$sess($admin); $r=Request::create('https://localhost/admin/settings','PUT',$d+['_token'=>$sa->token(),'section'=>'system']); $r->setLaravelSession($sa); $res=$http->handle($r); check('  (save accepted)', $res->getStatusCode()===302 && ! str_contains((string)$res->headers->get('Location'),'login'), 'status '.$res->getStatusCode().' -> '.$res->headers->get('Location')); return $res; };
$lobbyHas=function(Game $g) use($anon){ return str_contains((string)$anon('/')->getContent(), $g->slug); };

// Warm the snapshot while the switch is off, exactly as real traffic would.
check('warm: QTech game hidden from the lobby', ! $lobbyHas($qt));
check('warm: other games still shown', $lobbyHas($bb));

// No flushValueCache()/bump() here on purpose — the admin POST is all production does.
$put($sys+['qtech_live_to_players'=>'1']);
check('after ticking, lobby shows the QTech game', $lobbyHas($qt), 'stale snapshot survived the save');

$put($sys);
check('after unticking, lobby drops it again', ! $lobbyHas($qt), 'kill switch did not clear the snapshot');
check('other games unaffected', $lobbyHas($bb));

echo "\n— the game page lets the launcher reach the studio that hosts the game —\n";
// The frame we open points at QTech's launcher. The launcher then navigates
// that SAME frame onward to whichever studio actually runs the game —
// eu-server.ssgportal.com for SmartSoft, a different domain for every studio.
// The parent page's frame-src governs that hop, so the site-wide allowlist
// cannot apply here: it let the splash render and then blocked the game,
// leaving the player with a dead screen and nothing in the server log.
$put($sys+['qtech_live_to_players'=>'1']);
$player=User::forceCreate(['name'=>'P','username'=>'p'.uniqid(),'password'=>'x','role'=>'player','credits'=>500,'withdrawable_balance'=>500,'active'=>true]);
$sp=$sess($player);
$lr=Request::create("https://localhost/games/{$qt->slug}/provider/launch",'POST',['_token'=>$sp->token()]);
$lr->setLaravelSession($sp);
$lres=$http->handle($lr);
check('a real player can launch', $lres->getStatusCode()===200, 'status '.$lres->getStatusCode().' -> '.$lres->headers->get('Location'));
check('the launch URL is framed', str_contains((string)$lres->getContent(),'client.qtlauncher.com'));
preg_match('/frame-src ([^;]*)/', (string)$lres->headers->get('Content-Security-Policy'), $gm); $gframe=$gm[1] ?? '';
check('game page allows any https studio host', str_contains($gframe,'https:') && ! str_contains($gframe,'bigbangcasino'), $gframe);
preg_match('/frame-src ([^;]*)/', (string)$anon('/')->headers->get('Content-Security-Policy'), $lm); $lframe=$lm[1] ?? '';
check('every other page keeps the narrow allowlist', str_contains($lframe,'qtlauncher.com') && str_contains($lframe,'telegram.org') && $lframe !== $gframe, $lframe);
$put($sys);

echo "\n— the aux snapshots are revision-keyed, not fixed-key —\n";
$k='probe'.uniqid();
check('aux value is reused within a revision', CatalogCache::rememberAux($k,60,fn()=>'first')==='first' && CatalogCache::rememberAux($k,60,fn()=>'second')==='first');
CatalogCache::bump();
check('a bump orphans it', CatalogCache::rememberAux($k,60,fn()=>'third')==='third');

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } }
exit($fail?1:0);
