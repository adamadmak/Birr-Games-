<?php
/*
 * Staying signed in across a deploy.
 *
 * An operator pressed Delete on the agents list and got 419. The form was
 * fine — CSRF token present, route registered. The session behind the open page
 * was gone, because config/session.php falls back to the `file` driver unless
 * SESSION_DRIVER or APP_ENV=production is set, and NEITHER reaches this app:
 * the host builds the config cache at BUILD time with no runtime environment,
 * so every env() in it resolves empty.
 *
 * File sessions live in the container's filesystem. A deploy replaces the
 * container. So every deploy signed out everyone, and any page left open then
 * posted a token whose session no longer existed — a bare "Page Expired" on a
 * button that had worked a minute before, which reads as the button being
 * broken rather than the session having ended.
 *
 * Run: php tests/session_persistence_smoke.php   (local .env database)
 */
$ROOT = $argv[1] ?? dirname(__DIR__); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB,Auth,Schema};
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}

$provider = new App\Providers\AppServiceProvider($app);
$switch = (function () { return function () { $this->persistSessionsAcrossDeploys(); }; })()->bindTo($provider, App\Providers\AppServiceProvider::class);

try {

echo "— the fallback the host actually lands on —\n";
// Exactly how config/session.php resolves with no environment, which is the
// condition at build time on this host.
$fallback = (static function (): string {
    $driver = getenv('SESSION_DRIVER');
    if ($driver !== false && $driver !== '') { return $driver; }
    return getenv('APP_ENV') === 'production' ? 'database' : 'file';
})();
check('with no env set it would be file', $fallback === 'file', $fallback);

echo "\n— so the sessions table is used when it is there —\n";
check('the table exists here', Schema::hasTable('sessions'));
config(['session.driver' => 'file']);
$switch();
check('the driver becomes database', config('session.driver') === 'database', config('session.driver'));

echo "\n— an already-chosen driver is left alone —\n";
config(['session.driver' => 'redis']);
$switch();
check('redis is not overridden', config('session.driver') === 'redis', config('session.driver'));
config(['session.driver' => 'database']);
$switch();
check('database stays database', config('session.driver') === 'database');

echo "\n— and without the table it stays on files rather than breaking sign-in —\n";
$tmp = tempnam(sys_get_temp_dir(), 'nosessions').'.sqlite';
touch($tmp);
config(['database.connections.no_sessions' => ['driver' => 'sqlite', 'database' => $tmp, 'prefix' => '', 'foreign_key_constraints' => false]]);
$previous = config('database.default');
config(['database.default' => 'no_sessions']);
DB::purge('no_sessions');
config(['session.driver' => 'file']);
$switch();
check('no table, no switch', config('session.driver') === 'file', config('session.driver'));
config(['database.default' => $previous]);
@unlink($tmp);

echo "\n— a stale token gets a page that says what happened —\n";
config(['session.driver' => 'database']);
$player=User::forceCreate(['name'=>'P','username'=>'p'.uniqid(),'phone'=>'0916'.random_int(100000,999999),'password'=>'x','role'=>'player','active'=>true]);
Auth::login($player); $s=$app['session']->driver(); $s->start();
$r=Request::create('https://localhost/player/wallet/withdrawals','POST',['_token'=>'not-the-right-token','amount'=>50]);
$r->setLaravelSession($s);
$res=$http->handle($r);
check('a wrong token is refused', $res->getStatusCode()===419, 'status '.$res->getStatusCode());
$body=(string)$res->getContent();
check('the page names the cause', str_contains($body,'session expired') || str_contains($body,'Your session expired'), substr(strip_tags($body),0,120));
check('it says nothing was changed', str_contains($body,'Nothing was changed'));
check('and offers a way back', str_contains($body,'Sign in again'));
$player->forceDelete();

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
}
exit($fail?1:0);
