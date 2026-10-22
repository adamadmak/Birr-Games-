<?php
/*
 * QTech settlements have to reach `game_rounds`, not just the wallet.
 *
 * The wallet ledger records money and was always correct. `game_rounds` records
 * PLAY, and it is what Admin Rounds, the Casino Report's bet/payout/GGR
 * figures, the dashboard, tournaments and "recently played" all read. BigBang
 * wrote it; QTech did not, so once QTech became the only aggregator the
 * operator's casino reports went to zero while the money kept moving correctly.
 *
 * Run: php tests/qtech/rounds.php   (local .env database, rolled back)
 */
$ROOT = $argv[1] ?? dirname(__DIR__, 2); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,Game,GameProvider,GameCategory,QtechSession,GameRound,PlatformSetting};
use Illuminate\Http\Request; use Illuminate\Support\Facades\DB; use Illuminate\Support\Str;
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
config(['services.qtech.pass_key'=>'prod-key','services.qtech.currency'=>'ETB']); PlatformSetting::flushValueCache();
try {
DB::beginTransaction();

$prov=GameProvider::create(['name'=>'QT'.uniqid(),'slug'=>'qt'.uniqid(),'description'=>'x','accent'=>'emerald','active'=>true,'content_server_enabled'=>true,'sync_enabled'=>true]);
$cat=GameCategory::firstOrCreate(['slug'=>'slots'],['name'=>'Slots','description'=>'x','sort_order'=>1,'active'=>true]);
// A slug at the length the QTech synchroniser can actually mint (it caps at 140).
$longSlug=Str::limit('qtech-'.str_repeat('very-long-game-title-',6).'x', 140, '');
$gid='TK-'.uniqid();
$game=Game::create(['provider_id'=>$prov->id,'category_id'=>$cat->id,'provider_game_id'=>$gid,'external_launch_code'=>$gid,'title'=>'Round Test','slug'=>$longSlug,'sync_source'=>'qtech','playable'=>true,'active'=>true,'content_server_enabled'=>true,'art'=>'provider','symbol'=>'*','sort_order'=>1]);
$player=User::forceCreate(['name'=>'R','username'=>'r'.uniqid(),'password'=>'x','role'=>'player','credits'=>1000,'withdrawable_balance'=>1000,'active'=>true]);
$sess=QtechSession::create(['user_id'=>$player->id,'external_session_id'=>Str::lower(Str::random(48)),'currency'=>'ETB','status'=>'active','expires_at'=>now()->addHours(6),'last_activity_at'=>now()]);

$post=function(array $body) use($http,$sess){
    $r=Request::create('https://localhost/api/integrations/qtech/transactions','POST',[],[],[],
        ['HTTP_PASS_KEY'=>'prod-key','HTTP_WALLET_SESSION'=>$sess->external_session_id,'CONTENT_TYPE'=>'application/json'],
        json_encode($body));
    return $http->handle($r);
};
$rollback=function(array $body) use($http,$sess){
    $r=Request::create('https://localhost/api/integrations/qtech/transactions/rollback','POST',[],[],[],
        ['HTTP_PASS_KEY'=>'prod-key','HTTP_WALLET_SESSION'=>$sess->external_session_id,'CONTENT_TYPE'=>'application/json'],
        json_encode($body));
    return $http->handle($r);
};
$base=fn(array $o)=>$o+['playerId'=>'player_'.$player->id,'currency'=>'ETB','gameId'=>$gid,'device'=>'MOBILE','clientType'=>'HTML5','category'=>'CASINO/SLOT/5REEL','created'=>now()->toIso8601String()];
$rounds=fn(string $rid)=>GameRound::where('user_id',$player->id)->where('provider','qtech')->where('external_round_id',$rid)->first();

echo "— a bet opens a round —\n";
$r1='round-'.uniqid(); $bet1='bet-'.uniqid();
$res=$post($base(['txnType'=>'DEBIT','txnId'=>$bet1,'roundId'=>$r1,'amount'=>10,'completed'=>false]));
check('debit accepted', in_array($res->getStatusCode(),[200,201],true), (string)$res->getContent());
$row=$rounds($r1);
check('a round row exists', (bool)$row);
check('stake recorded', $row && (float)$row->stake===10.0, $row?->stake);
check('payout still zero', $row && (float)$row->payout===0.0);
check('not settled yet', $row && ($row->result['settled'] ?? null)===false);
check('linked to the local game by slug', $row && $row->game===$longSlug, $row?->game);
check('the long slug survived the column', $row && strlen($row->game)>80, strlen($row?->game ?? ''));
check('carries the wallet session', $row && $row->provider_session_id===$sess->external_session_id);

echo "\n— the win lands on the same round —\n";
$res=$post($base(['txnType'=>'CREDIT','txnId'=>'win-'.uniqid(),'roundId'=>$r1,'betId'=>$bet1,'amount'=>25,'completed'=>'true']));
check('credit accepted', in_array($res->getStatusCode(),[200,201],true), (string)$res->getContent());
check('still one round for this roundId', GameRound::where('external_round_id',$r1)->count()===1);
$row=$rounds($r1);
check('payout recorded', $row && (float)$row->payout===25.0, $row?->payout);
check('stake unchanged', $row && (float)$row->stake===10.0);
check('marked settled', $row && ($row->result['settled'] ?? null)===true);
check('house result is stake minus payout', $row && abs($row->houseResult()-(-15.0))<0.001, $row?->houseResult());

echo "\n— a retry of the same transaction must not double count —\n";
$dup='dup-'.uniqid(); $r2='round-'.uniqid();
$post($base(['txnType'=>'DEBIT','txnId'=>$dup,'roundId'=>$r2,'amount'=>7,'completed'=>false]));
$post($base(['txnType'=>'DEBIT','txnId'=>$dup,'roundId'=>$r2,'amount'=>7,'completed'=>false]));
$row=$rounds($r2);
check('stake counted once', $row && (float)$row->stake===7.0, $row?->stake);
check('one round row', GameRound::where('external_round_id',$r2)->count()===1);

echo "\n— a rollback takes the stake back off —\n";
$r3='round-'.uniqid(); $bet3='bet-'.uniqid();
$post($base(['txnType'=>'DEBIT','txnId'=>$bet3,'roundId'=>$r3,'amount'=>12,'completed'=>false]));
check('stake on the board', (float)$rounds($r3)->stake===12.0);
$res=$rollback($base(['txnId'=>'rb-'.uniqid(),'roundId'=>$r3,'betId'=>$bet3,'amount'=>12,'completed'=>'true']));
check('rollback accepted', $res->getStatusCode()===200, (string)$res->getContent());
$row=$rounds($r3);
check('stake reversed to zero', $row && (float)$row->stake===0.0, $row?->stake);
check('no phantom payout', $row && (float)$row->payout===0.0);

echo "\n— the reports can see it all —\n";
check('rounds are attributed to qtech', GameRound::where('user_id',$player->id)->where('provider','qtech')->count()===3);
check('none are attributed to bigbang', GameRound::where('user_id',$player->id)->where('provider','bigbang')->count()===0);
$totalStake=(float)GameRound::where('user_id',$player->id)->where('provider','qtech')->sum('stake');
$totalPayout=(float)GameRound::where('user_id',$player->id)->where('provider','qtech')->sum('payout');
check('report totals add up', abs($totalStake-17.0)<0.001 && abs($totalPayout-25.0)<0.001, "stake $totalStake payout $totalPayout");

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } }
exit($fail?1:0);
