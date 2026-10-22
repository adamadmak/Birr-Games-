<?php
/*
 * Applying schema changes on a host with no shell.
 *
 * Whether a deploy runs migrations here depends on one string in the hosting
 * dashboard, and the two brands are not set the same way — on casinogame a
 * migration has been a no-op since the app was created: it ships, the build
 * goes green, and the database is untouched. That was found the hard way, by
 * shipping the same migration to both brands and watching only one take.
 *
 * Run: php tests/pending_migrations_smoke.php   (local .env database)
 */
$ROOT = $argv[1] ?? dirname(__DIR__); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
use App\Services\PendingMigrations;
use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB,Auth,Schema};
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
$service=new PendingMigrations();
try {

echo "— it can see what is waiting —\n";
$status=$service->status();
check('reports a supported connection', $status['supported']===true);
check('counts what is on disk', $status['onDisk']>0, (string)$status['onDisk']);
check('and nothing is pending on an up-to-date database', $status['pending']===[], json_encode($status['pending']));
check('applied matches on-disk when nothing is pending', $status['applied']===$status['onDisk'], "{$status['applied']} vs {$status['onDisk']}");

echo "\n— a migration removed from the record shows up as waiting —\n";
$victim=DB::table('migrations')->orderByDesc('id')->first();
DB::table('migrations')->where('id',$victim->id)->delete();
$after=$service->status();
check('it is listed as pending', in_array($victim->migration, $after['pending'], true), json_encode($after['pending']));
check('the applied count drops by one', $after['applied']===$status['applied']-1);
// Put it back by hand: re-running it would try to create what already exists.
DB::table('migrations')->insert(['migration'=>$victim->migration,'batch'=>$victim->batch]);
check('restored for the next check', $service->status()['pending']===[]);

echo "\n— applying when nothing is waiting is a safe no-op —\n";
$result=$service->apply();
check('it reports that nothing ran', $result['ran']===[], json_encode($result['ran']));
check('and the schema is untouched', Schema::hasTable('games') && Schema::hasTable('migrations'));

echo "\n— the admin surface —\n";
DB::beginTransaction();
$admin=User::forceCreate(['name'=>'A','username'=>'a'.uniqid(),'password'=>'x','role'=>'admin','active'=>true]);
Auth::login($admin); $s=$app['session']->driver(); $s->start();
$page=(string)$http->handle(tap(Request::create('https://localhost/admin/settings','GET',['tab'=>'system']), fn($r)=>$r->setLaravelSession($s)))->getContent();
check('the settings page renders the panel', str_contains($page,'Schema updates'));
check('and says it is up to date', str_contains($page,'Up to date'));
$r=Request::create('https://localhost/admin/settings/migrations/run','POST',['_token'=>$s->token()]);
$r->setLaravelSession($s);
$res=$http->handle($r);
check('the button is reachable and not a 405', $res->getStatusCode()===302 && ! str_contains((string)$res->headers->get('Location'),'login'), 'status '.$res->getStatusCode());

echo "\n— and a player cannot press it —\n";
$player=User::forceCreate(['name'=>'P','username'=>'p'.uniqid(),'password'=>'x','role'=>'player','active'=>true]);
Auth::logout(); Auth::login($player); $ps=$app['session']->driver(); $ps->start();
$pr=Request::create('https://localhost/admin/settings/migrations/run','POST',['_token'=>$ps->token()]);
$pr->setLaravelSession($ps);
$pres=$http->handle($pr);
check('a player is refused', in_array($pres->getStatusCode(),[403,404,302],true) && ! str_contains((string)$pres->getContent(),'Applied'), 'status '.$pres->getStatusCode());
DB::rollBack();

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } }
exit($fail?1:0);
