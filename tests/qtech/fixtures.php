<?php
// Standalone check: Settings -> "Issue Test Credentials" mints what QTech's verifier needs.
// Run: php tests/qtech/fixtures.php   (uses the local .env database; wraps everything in a rolled-back transaction)
$ROOT = $argv[1] ?? dirname(__DIR__, 2);
require "$ROOT/vendor/autoload.php"; $app = require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User, QtechSession, PlatformSetting}; use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB, Auth};
$http = $app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
config(['services.qtech.pass_key'=>'prod-key','services.qtech.pass_key_staging'=>'stag-key','services.qtech.currency'=>'ETB']); PlatformSetting::flushValueCache();
try {
DB::beginTransaction();
$admin = User::forceCreate(['name'=>'A','username'=>'adm'.uniqid(),'password'=>'x','role'=>'admin','active'=>true]);
Auth::login($admin); $s = $app['session']->driver(); $s->start();
$press = function() use ($http,$s){ $r=Request::create('https://localhost/admin/settings/qtech/test-credentials','POST',['_token'=>$s->token()]); $r->setLaravelSession($s); return $http->handle($r); };
$hit = fn($path,$key,$tok)=>$http->handle(Request::create("https://localhost/api/integrations/$path",'GET',[],[],[],array_filter(['HTTP_PASS_KEY'=>$key,'HTTP_WALLET_SESSION'=>$tok])));
echo "— pressing the button —\n";
check('redirects to the System tab', str_contains((string)$press()->headers->get('Location'),'tab=system'));
$i = $s->get('qtech_test_credentials'); check('credentials issued (48-char token)', is_array($i) && strlen($i['session'] ?? '')===48);
$p = User::where('username','qtech_test')->first(); $b = User::where('username','qtech_blocked')->first();
check('test player active with 1000', $p && $p->active && (float)$p->credits===1000.0);
check('blocked player inactive', $b && !$b->active);
check('three live sessions', QtechSession::whereIn('user_id',[$p->id,$b->id])->where('status','active')->count()===3);
echo "— the wallet honours them as the verifier will use them —\n";
check('staging + live token -> 200', $hit('qtech-staging/accounts/qtech_test/session','stag-key',$i['session'])->getStatusCode()===200);
check('staging + expired token -> 400', $hit('qtech-staging/accounts/qtech_test/session','stag-key',$i['expired_session'])->getStatusCode()===400);
check('blocked player -> 403', $hit('qtech-staging/accounts/qtech_blocked/session','stag-key',$i['blocked_session'])->getStatusCode()===403);
check('get-balance without a session -> 200', $hit('qtech-staging/accounts/qtech_test/balance','stag-key',null)->getStatusCode()===200);
check('production prefix accepts the same fixtures', $hit('qtech/accounts/qtech_test/session','prod-key',$i['session'])->getStatusCode()===200);
echo "— re-issuing rotates —\n";
DB::table('users')->where('id',$p->id)->update(['credits'=>3]); $press(); $j = $s->get('qtech_test_credentials');
check('new token differs', $j['session'] !== $i['session']);
check('old token refused', $hit('qtech-staging/accounts/qtech_test/session','stag-key',$i['session'])->getStatusCode()===400);
check('balance reset', (float)$p->fresh()->credits===1000.0);
check('still exactly three live sessions', QtechSession::whereIn('user_id',[$p->id,$b->id])->where('status','active')->count()===3);
echo "— shown once, never the key —\n";
$pg = Request::create('https://localhost/admin/settings?tab=system','GET'); $pg->setLaravelSession($s); $html=(string)$http->handle($pg)->getContent();
check('cfg rendered with fresh token and a placeholder key', str_contains($html,'walletsession = '.$j['session']) && str_contains($html,'REPLACE_WITH_STAGING_PASS_KEY') && !str_contains($html,'stag-key'));
$pg2 = Request::create('https://localhost/admin/settings?tab=system','GET'); $pg2->setLaravelSession($s);
check('gone on the next load', !str_contains((string)$http->handle($pg2)->getContent(),'walletsession = '));
echo "— permissions —\n";
Auth::logout(); $lim = User::forceCreate(['name'=>'P','username'=>'p'.uniqid(),'password'=>'x','role'=>'admin','active'=>true,'admin_role'=>'manager','admin_permissions'=>['promotions.manage','dashboard.view']]);
Auth::login($lim); $s3=$app['session']->driver(); $s3->start(); $r=Request::create('https://localhost/admin/settings/qtech/test-credentials','POST',['_token'=>$s3->token()]); $r->setLaravelSession($s3);
check('promotions-only admin -> 403', $http->handle($r)->getStatusCode()===403);
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
