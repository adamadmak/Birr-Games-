<?php
// Standalone check: "Repair Test Players" restores the fixtures without rotating their tokens.
$ROOT = $argv[1] ?? dirname(__DIR__, 2);
require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,QtechSession,PlatformSetting}; use App\Services\QtechTestCredentials; use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB,Auth};
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
config(['services.qtech.pass_key_staging'=>'stg','services.qtech.api_base_url'=>'https://api-int.qtplatform.com','services.qtech.currency'=>'ETB']); PlatformSetting::flushValueCache();
try {
DB::beginTransaction();
$issued=app(QtechTestCredentials::class)->issue(); $t=User::where('username','qtech_test')->first(); $b=User::where('username','qtech_blocked')->first();
$bal=fn($u)=>$http->handle(Request::create("https://localhost/api/integrations/qtech-staging/accounts/$u/balance",'GET',[],[],[],['HTTP_PASS_KEY'=>'stg']))->getStatusCode();
$t->forceFill(['active'=>false,'credits'=>3])->save(); $b->forceFill(['active'=>true])->save();
check('inverted: qtech_test 403', $bal('qtech_test')===403); check('inverted: qtech_blocked 200', $bal('qtech_blocked')===200);
Auth::login(User::forceCreate(['name'=>'A','username'=>'a'.uniqid(),'password'=>'x','role'=>'admin','active'=>true])); $s=$app['session']->driver(); $s->start();
$r=Request::create('https://localhost/admin/settings/qtech/repair-test-players','POST',['_token'=>$s->token()]); $r->setLaravelSession($s); $res=$http->handle($r);
check('repair -> 302 with status', $res->getStatusCode()===302 && str_contains((string)$s->get('admin_status'),'repaired'));
check('qtech_test 200 again', $bal('qtech_test')===200); check('qtech_blocked 403 again', $bal('qtech_blocked')===403);
check('balance reset', (float)$t->fresh()->credits===1000.0);
$vs=$http->handle(Request::create('https://localhost/api/integrations/qtech-staging/accounts/qtech_test/session','GET',[],[],[],['HTTP_PASS_KEY'=>'stg','HTTP_WALLET_SESSION'=>$issued['session']]));
check('original live token still verifies', $vs->getStatusCode()===200);
check('three live sessions unchanged', QtechSession::whereIn('user_id',[$t->id,$b->id])->where('status','active')->count()===3);
Auth::logout(); check('one-time password still signs in', Auth::attempt(['username'=>'qtech_test','password'=>$issued['player_password']])); Auth::logout();
$t->delete(); $code=$bal('qtech_test'); check('trashed fixture refused (400/403)', in_array($code,[400,403],true), (string)$code);
app(QtechTestCredentials::class)->repair(); check('restored -> 200', $bal('qtech_test')===200);
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
