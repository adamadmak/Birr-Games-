<?php
/*
 * Three operator complaints, and what each turned out to be.
 *
 * 1. "No light/dark option on the agent dashboard." The toggle existed — but
 *    only inside .agent-mobile-header, which is display:none above 900px, and
 *    the portal defaults to dark. On a desktop there was nothing to press.
 *
 * 2. "Deleting payment gateways and agents doesn't work." Two separate things.
 *    The gateway guard refused on ATTACHED AGENT ACCOUNTS, and every gateway an
 *    operator really uses has those, so Delete refused every time. And every
 *    confirm was an inline onsubmit building a JS string out of the record's
 *    own name: Blade escapes an apostrophe to &#039;, the browser decodes it
 *    back to ' before the JS parser runs, the string ends early, the handler is
 *    a syntax error — and a form with a broken onsubmit submits WITHOUT asking.
 *
 * 3. "Records should be empty when we go live." There is no shell on this host,
 *    so it needs an admin control; and it must not be able to reach anything
 *    that is configuration rather than a record of something that happened.
 *
 * Run: php tests/admin_controls_smoke.php   (local .env database, rolled back)
 */
$ROOT = $argv[1] ?? dirname(__DIR__); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,Agent,AgentBankAccount,AgentOrder,PaymentGateway,PlatformSetting,Game,WalletTransaction};
use App\Services\GoLiveReset;
use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB,Auth,Schema}; use Illuminate\Support\Str;
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}

try {
DB::beginTransaction();

$admin=User::forceCreate(['name'=>'A','username'=>'a'.uniqid(),'phone'=>'0913'.random_int(100000,999999),'password'=>'x','role'=>'admin','active'=>true]);
$session=null;
$as=function(User $who) use($app,&$session){ Auth::logout(); Auth::login($who); $session=$app['session']->driver(); $session->start(); };
$get=function(string $uri) use($http,&$session){
    $r=Request::create('https://localhost'.$uri,'GET'); $r->setLaravelSession($session);
    return (string)$http->handle($r)->getContent();
};
$send=function(string $method, string $uri, array $fields=[]) use($http,&$session){
    $r=Request::create($uri,'POST',$fields+['_token'=>$session->token(),'_method'=>$method]);
    $r->setLaravelSession($session);
    return $http->handle($r);
};
$mkAgent=fn(string $nick)=>Agent::create(['first_name'=>'T','last_name'=>'A','nickname'=>$nick,'phone'=>'09'.random_int(10000000,99999999),'password'=>'secret-pass-1234','balance'=>0,'commission_enabled'=>false,'commission_rate'=>0,'active'=>true]);
$mkGateway=fn(string $name)=>PaymentGateway::create(['name'=>$name,'code'=>Str::slug($name.uniqid(),'_'),'type'=>'bank','account_label'=>'Acct','supports_deposit'=>true,'supports_withdrawal'=>true,'active'=>true]);
$mkAccount=fn(Agent $a, PaymentGateway $g)=>AgentBankAccount::create(['agent_id'=>$a->id,'payment_gateway_id'=>$g->id,'account_name'=>'Holder','account_number'=>(string) random_int(1000000000,9999999999),'notes'=>'','active'=>true]);

echo "— 1. the agent portal has a theme switch that is actually reachable —\n";
$agent=$mkAgent('themer'.uniqid());
Auth::guard('agent')->login($agent); $agentSession=$app['session']->driver(); $agentSession->start();
$portal=(string)$http->handle(tap(Request::create('https://localhost/agent','GET'), fn($r)=>$r->setLaravelSession($agentSession)))->getContent();
check('the dashboard renders', str_contains($portal,'agent-shell'), 'portal did not load');
// Scoped to the sidebar chip: the mobile header already had one, and it is
// hidden above 900px, so finding a toggle anywhere on the page proved nothing.
// Cut at </aside>: a fixed-length window ran past the sidebar into the mobile
// header, which has a toggle of its own, so removing the sidebar one still passed.
$chip=(function(string $html){
    $i=strpos($html,'agent-profile-chip');
    if ($i===false) { return ''; }
    $j=strpos($html,'</aside>',$i);
    return substr($html,$i,($j===false ? 2200 : $j-$i));
})($portal);
check('the sidebar carries a theme toggle', str_contains($chip,'data-theme-toggle'), 'only the hidden mobile header has one');
check('and it is a button, not a form control', str_contains($chip,'agent-theme-button'));
$css=file_get_contents(public_path('css/agent-portal.css'));
check('the dark palette exists to switch to', str_contains($css,'[data-agent-theme=dark]'));
check('the switch is styled', str_contains($css,'.agent-theme-button'));
check('the mobile header keeps its own', substr_count($portal,'data-theme-toggle')>=2, 'the mobile toggle was lost');
Auth::guard('agent')->logout();

echo "\n— 2a. no confirmation is built out of a record's name any more —\n";
$views=[]; $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
foreach ($iterator as $file) { if ($file->isFile() && str_ends_with($file->getFilename(),'.blade.php')) { $views[]=$file->getPathname(); } }
$inline=[];
foreach ($views as $file) { if (str_contains(file_get_contents($file),'onsubmit="return confirm(')) { $inline[]=basename($file); } }
check('not one inline onsubmit confirm is left', $inline===[], implode(', ', $inline));
$adminLayout=file_get_contents(resource_path('views/layouts/admin.blade.php'));
$agentLayout=file_get_contents(resource_path('views/layouts/agent.blade.php'));
check('the admin layout loads the handler', str_contains($adminLayout,'/js/confirm-action.js'));
check('so does the agent portal', str_contains($agentLayout,'/js/confirm-action.js'));
// The handler is the only thing between a click and an irreversible delete, so
// it is driven against a DOM rather than read. Skipped where node is absent.
$node=trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "  --   node is not installed here; tests/confirm_action.node.js not run\n";
} else {
    $out=(string) shell_exec(escapeshellarg($node).' '.escapeshellarg(base_path('tests/confirm_action.node.js')).' 2>&1');
    preg_match('/(\d+) passed, (\d+) failed/', $out, $m);
    check('the handler passes all '.($m[1] ?? '?').' of its own checks', ($m[2] ?? '1') === '0', trim($out));
}

echo "\n— and the apostrophe that used to break it is just text —\n";
$oBrien=$mkAgent("O'Brien-".substr(uniqid(),-6));
$as($admin);
$html=$get('/admin/agents');
preg_match('#<form[^>]*agents/'.$oBrien->id.'"[^>]*>#', $html, $m);
$tag=$m[0] ?? '';
check('the delete form is rendered', $tag!=='', 'no form found');
check('it carries data-confirm', str_contains($tag,'data-confirm='), $tag);
check('and no onsubmit at all', ! str_contains($tag,'onsubmit'), $tag);
// &#039; inside an attribute is safe; inside a JS string literal it was not.
check('the apostrophe is escaped as an entity', str_contains($tag,'&#039;'), $tag);

echo "\n— 2b. a gateway with only routing accounts can be deleted —\n";
$gw=$mkGateway('Routing Only '.uniqid());
$acct=$mkAccount($oBrien,$gw);
$res=$send('DELETE','/admin/payment-gateways/'.$gw->id);
check('the delete is accepted', $res->getStatusCode()===302);
check('the gateway is gone', PaymentGateway::find($gw->id)===null, 'still refused — the old guard is back');
check('and its routing accounts went with it', AgentBankAccount::find($acct->id)===null);

echo "\n— but not one money has moved through —\n";
$used=$mkGateway('In Use '.uniqid());
$player=User::forceCreate(['name'=>'P','username'=>'p'.uniqid(),'phone'=>'0914'.random_int(100000,999999),'password'=>'x','role'=>'player','active'=>true,'credits'=>100,'withdrawable_balance'=>100]);
$order=AgentOrder::create(['user_id'=>$player->id,'agent_id'=>$oBrien->id,'payment_gateway_id'=>$used->id,'type'=>'deposit','amount'=>500,'commission'=>0,'reference'=>'T-'.uniqid(),'idempotency_key'=>(string) Str::uuid(),'account_name'=>'Holder','account_number'=>'1','status'=>'success','notes'=>'x']);
$send('DELETE','/admin/payment-gateways/'.$used->id);
check('it survives', PaymentGateway::find($used->id)!==null);
check('so the order still names its method', AgentOrder::find($order->id)?->payment_gateway_id===$used->id);
check('and the refusal says money moved', str_contains($get('/admin/payment-gateways'),'Money has moved through'));

echo "\n— 3. the go-live reset counts what it would clear —\n";
$reset=app(GoLiveReset::class);
$preview=$reset->preview();
check('every group is described', count($preview)>=7 && isset($preview['orders']['label'],$preview['players']['note']));
check('orders are counted', $preview['orders']['count']>=1, (string) $preview['orders']['count']);
check('players are counted', $preview['players']['count']>=1, (string) $preview['players']['count']);

echo "\n— it refuses without the word, and deletes nothing —\n";
$before=AgentOrder::count();
$threw=null; try { $reset->apply(['orders'],'yes'); } catch (\Throwable $e) { $threw=$e->getMessage(); }
check('a wrong confirmation is refused', $threw!==null && str_contains($threw,GoLiveReset::CONFIRMATION), (string) $threw);
check('and nothing was cleared', AgentOrder::count()===$before);
$threw=null; try { $reset->apply([],GoLiveReset::CONFIRMATION); } catch (\Throwable $e) { $threw=$e->getMessage(); }
check('ticking nothing is refused too', $threw!==null, (string) $threw);
check('still nothing cleared', AgentOrder::count()===$before);

echo "\n— it clears only what was ticked —\n";
$settings=PlatformSetting::count(); $gateways=PaymentGateway::count(); $games=Game::count(); $agents=Agent::count();
$removed=$reset->apply(['orders'],GoLiveReset::CONFIRMATION);
check('the orders are gone', AgentOrder::count()===0, (string) AgentOrder::count());
check('it reports how many', ($removed['orders'] ?? 0)>=1);
check('players are untouched — they were not ticked', User::where('role','player')->count()>=1);
check('and configuration is untouched', PlatformSetting::count()===$settings && PaymentGateway::count()===$gateways && Game::count()===$games && Agent::count()===$agents);

echo "\n— player accounts go only when chosen, and take nobody else —\n";
$adminsBefore=User::where('role','admin')->count();
$reset->apply(['players'],GoLiveReset::CONFIRMATION);
check('no player is left, soft-deleted or not', User::withTrashed()->where('role','player')->count()===0);
check('administrators are still there', User::where('role','admin')->count()===$adminsBefore);
check('agents are still there', Agent::count()===$agents);

echo "\n— agent float is a reset, not a deletion —\n";
$oBrien->forceFill(['balance'=>5000])->save();
$reset->apply(['balances'],GoLiveReset::CONFIRMATION);
check('the balance is zero', (float) Agent::find($oBrien->id)->balance===0.0);
check('the agent still exists', Agent::find($oBrien->id)!==null);

echo "\n— the panel is on the page, and only for an administrator —\n";
$system=$get('/admin/settings?tab=system');
check('the panel renders', str_contains($system,'Clear test data for go-live'));
check('it names the confirmation word', str_contains($system,'Type '.GoLiveReset::CONFIRMATION.' to confirm'));
check('its form escapes the settings form\'s PUT spoof', str_contains($system,'id="go-live-reset-form"') && str_contains($system,'form="go-live-reset-form"'));
$player2=User::forceCreate(['name'=>'P2','username'=>'q'.uniqid(),'phone'=>'0915'.random_int(100000,999999),'password'=>'x','role'=>'player','active'=>true]);
$as($player2);
$res=$send('POST','/admin/settings/go-live-reset',['groups'=>['orders'],'confirmation'=>GoLiveReset::CONFIRMATION]);
check('a player cannot reach it', in_array($res->getStatusCode(),[403,404,302],true));
check('and the tables are still theirs', Schema::hasTable('agent_orders') && Schema::hasTable('users'));

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } }
exit($fail?1:0);
