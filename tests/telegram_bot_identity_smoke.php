<?php
/*
 * Each brand links to its own Telegram bot, or to none at all.
 *
 * kulubet was forked from casinogame, and the fork inherited the sibling's bot
 * username as a committed default in config/services.php — plus the same
 * literal written straight into the admin sidebar, the player profile and the
 * agent portal. Those three read the raw config rather than the service that
 * knows what an operator configured, so with nothing set in the dashboard every
 * Telegram link on the site pointed at a different casino's bot, and an
 * operator who DID configure their own bot still saw the wrong one.
 *
 * The rule these checks hold: one source of truth for the bot, and an unset bot
 * resolves to nothing so the links disappear rather than going somewhere wrong.
 *
 * Run: php tests/telegram_bot_identity_smoke.php   (local .env database, rolled back)
 */
$ROOT = $argv[1] ?? dirname(__DIR__); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,PlatformSetting};
use App\Services\TelegramBotService;
use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB,Auth};
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
$svc = app(TelegramBotService::class);
$set = function (?string $value) {
    DB::table('platform_settings')->where('key','telegram_bot_username')->delete();
    if ($value !== null) {
        DB::table('platform_settings')->insert(['key'=>'telegram_bot_username','value'=>$value]);
    }
    PlatformSetting::flushValueCache();
};
try {
DB::beginTransaction();

echo "— no brand carries another brand's bot as a literal —\n";
// The sibling's handle must not appear anywhere in this repository's source.
$sibling = 'lucygames' . '_bot';
$own = trim((string) config('services.telegram.bot_username'));
$hits = [];
foreach (['config', 'app', 'resources', 'routes'] as $dir) {
    $out = shell_exec('grep -rl '.escapeshellarg($sibling).' '.escapeshellarg(base_path($dir)).' 2>/dev/null');
    foreach (array_filter(explode("\n", (string) $out)) as $file) { $hits[] = str_replace(base_path().'/', '', $file); }
}
// Only legitimate on the brand whose bot it actually is.
$isOwnBot = $own === $sibling;
check($isOwnBot ? 'this IS the brand that owns it, so the literal is its own' : 'the sibling brand\'s bot appears nowhere in the source',
    $isOwnBot || $hits === [], implode(', ', $hits));

echo "\n— the configured bot is the one that wins —\n";
$set('brand_own_bot');
check('a configured bot is used', $svc->botUsername()==='brand_own_bot', $svc->botUsername());
$set('@brand_own_bot');
check('a leading @ is stripped', $svc->botUsername()==='brand_own_bot', $svc->botUsername());

echo "\n— and it reaches the pages that link to it —\n";
$admin=User::forceCreate(['name'=>'A','username'=>'a'.uniqid(),'password'=>'x','role'=>'admin','active'=>true]);
Auth::login($admin); $s=$app['session']->driver(); $s->start();
$adminPage=(string)$http->handle(tap(Request::create('https://localhost/admin','GET'), fn($r)=>$r->setLaravelSession($s)))->getContent();
check('the admin sidebar links to the configured bot', str_contains($adminPage,'t.me/brand_own_bot'), 'link not found');
check('and not to the sibling brand', ! str_contains($adminPage,'t.me/'.$sibling) || $isOwnBot);

echo "\n— an unset bot links nowhere, rather than somewhere wrong —\n";
$set(null);
if ($isOwnBot) {
    // This brand keeps its own handle as the config default, so "unset" still
    // resolves — correctly, to itself.
    check('this brand falls back to its own bot', $svc->botUsername()===$sibling, $svc->botUsername());
} else {
    check('resolves to nothing', $svc->botUsername()==='', $svc->botUsername());
    Auth::login($admin); $s2=$app['session']->driver(); $s2->start();
    $bare=(string)$http->handle(tap(Request::create('https://localhost/admin','GET'), fn($r)=>$r->setLaravelSession($s2)))->getContent();
    check('the admin sidebar shows no Telegram link at all', ! str_contains($bare,'t.me/'), 'a t.me link is still rendered');
    check('and certainly not the sibling\'s', ! str_contains($bare,$sibling));
}

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } PlatformSetting::flushValueCache(); }
exit($fail?1:0);
