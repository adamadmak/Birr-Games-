<?php
/*
 * Removing something added by mistake, without removing the record of money.
 *
 * Agents, agent payment accounts and payment gateways could only be enabled and
 * disabled, so anything added in error stayed on the screen for good. Delete is
 * not simply the missing button, though: the foreign keys decide what deleting
 * actually does, and two of the three are quietly destructive.
 *
 *   agent_orders.agent_id            nullOnDelete  — blanks the agent out of
 *   wallet_transactions.agent_id     nullOnDelete    every order they handled
 *   agent_bank_accounts.agent_id     cascade
 *   agent_orders.payment_gateway_id  nullOnDelete  — blanks the method out of
 *   agent_bank_accounts.gateway      cascade         every settled payment
 *
 * So an agent or a gateway with history cannot be deleted without erasing part
 * of the accounting record, and those are refused. An agent PAYMENT ACCOUNT is
 * different: the order copies its name and number at the moment it is created
 * and never points back at the row, so it can go.
 *
 * The other half is passwords, where the answer is the opposite of a feature:
 * they are bcrypt hashes and CANNOT be shown. What ships is the only readable
 * form there is — seeing what you type as you set one.
 *
 * Run: php tests/admin_delete_and_password_smoke.php   (local .env, rolled back)
 */
$ROOT = $argv[1] ?? dirname(__DIR__); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,Agent,AgentBankAccount,AgentOrder,PaymentGateway,WalletTransaction};
use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB,Auth,Hash}; use Illuminate\Support\Str;
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}

try {
DB::beginTransaction();

$admin=User::forceCreate(['name'=>'A','username'=>'a'.uniqid(),'phone'=>'0913'.random_int(100000,999999),'password'=>'x','role'=>'admin','active'=>true]);
$player=User::forceCreate(['name'=>'P','username'=>'p'.uniqid(),'phone'=>'0914'.random_int(100000,999999),'password'=>'x','role'=>'player','active'=>true]);
$session=null;
$as=function(User $who) use($app,&$session){ Auth::logout(); Auth::login($who); $session=$app['session']->driver(); $session->start(); };
/** Send an admin action and hand back the response. */
$send=function(string $method, string $uri) use($http,&$session){
    $r=Request::create($uri,'POST',['_token'=>$session->token(),'_method'=>$method]);
    $r->setLaravelSession($session);
    return $http->handle($r);
};
$mkAgent=fn(string $nick)=>Agent::create(['first_name'=>'T','last_name'=>'A','nickname'=>$nick.uniqid(),'phone'=>'09'.random_int(10000000,99999999),'password'=>'secret-pass-1234','balance'=>0,'commission_enabled'=>false,'commission_rate'=>0,'active'=>true]);
$mkGateway=fn(string $name)=>PaymentGateway::create(['name'=>$name.uniqid(),'code'=>Str::slug($name.uniqid(),'_'),'type'=>'bank','account_label'=>'Account number','supports_deposit'=>true,'supports_withdrawal'=>true,'active'=>true]);
$mkAccount=fn(Agent $a, PaymentGateway $g)=>AgentBankAccount::create(['agent_id'=>$a->id,'payment_gateway_id'=>$g->id,'account_name'=>'Test Holder','account_number'=>(string) random_int(1000000000,9999999999),'notes'=>'','active'=>true]);

echo "— a payment account added by mistake can just go —\n";
$as($admin);
$agent=$mkAgent('keeper'); $gateway=$mkGateway('Test Bank'); $account=$mkAccount($agent,$gateway);
// A deposit taken through it, exactly as PlayerDepositController records one.
$order=AgentOrder::create(['user_id'=>$player->id,'agent_id'=>$agent->id,'payment_gateway_id'=>$gateway->id,'type'=>'deposit','amount'=>500,'commission'=>0,'reference'=>'T-'.uniqid(),'idempotency_key'=>(string) Str::uuid(),'account_name'=>$account->account_name,'account_number'=>$account->account_number,'status'=>'success','notes'=>'x']);
$res=$send('DELETE','/admin/agent-bank-info/'.$account->id);
check('the delete is accepted', $res->getStatusCode()===302, 'status '.$res->getStatusCode());
check('the account is gone', AgentBankAccount::find($account->id)===null);
$order->refresh();
check('the deposit taken through it survives', $order->exists);
check('and still shows the account it was paid into', $order->account_number===$account->account_number && $order->account_name==='Test Holder');
check('with the agent and the method intact', (int) $order->agent_id===$agent->id && (int) $order->payment_gateway_id===$gateway->id);

echo "\n— an agent who handled money is refused, not silently unstitched —\n";
$before=AgentOrder::where('agent_id',$agent->id)->count();
$res=$send('DELETE','/admin/agents/'.$agent->id);
check('the agent is still there', Agent::find($agent->id)!==null);
check('their orders still name them', AgentOrder::where('agent_id',$agent->id)->count()===$before && $before>0);
$page=(string)$http->handle(tap(Request::create('https://localhost/admin/agents','GET'), fn($r)=>$r->setLaravelSession($session)))->getContent();
check('and the refusal says why', str_contains($page,'Disable the agent instead'), 'no explanation reached the screen');
check('naming what it is holding', str_contains($page,'1 order'), 'the count was not quoted');

echo "\n— nor one holding a balance —\n";
$rich=$mkAgent('rich'); $rich->forceFill(['balance'=>250])->save();
$send('DELETE','/admin/agents/'.$rich->id);
check('refused while the balance stands', Agent::find($rich->id)!==null);
$rich->forceFill(['balance'=>0])->save();

echo "\n— an agent created by mistake goes, and their accounts with them —\n";
$spare=$mkAgent('spare'); $spareAccount=$mkAccount($spare,$gateway);
$res=$send('DELETE','/admin/agents/'.$spare->id);
check('the agent is deleted', Agent::find($spare->id)===null);
check('their payment account went too', AgentBankAccount::find($spareAccount->id)===null);
check('and nobody else was touched', Agent::find($agent->id)!==null && Agent::find($rich->id)!==null);

echo "\n— a gateway money has moved through is refused —\n";
$send('DELETE','/admin/payment-gateways/'.$gateway->id);
check('the gateway survives', PaymentGateway::find($gateway->id)!==null);
check('so does the order that used it', AgentOrder::find($order->id)?->payment_gateway_id === $gateway->id, 'the method was blanked out');
$page=(string)$http->handle(tap(Request::create('https://localhost/admin/payment-gateways','GET'), fn($r)=>$r->setLaravelSession($session)))->getContent();
check('and the refusal points at Disable', str_contains($page,'Disable the gateway instead'));

echo "\n— a gateway nothing has used is deleted —\n";
$unused=$mkGateway('Added By Mistake');
$res=$send('DELETE','/admin/payment-gateways/'.$unused->id);
check('it is gone', PaymentGateway::find($unused->id)===null);
check('and the one in use is still there', PaymentGateway::find($gateway->id)!==null);

echo "\n— an attached agent account does NOT refuse it —\n";
// It did at first, and that made the button useless: every gateway an operator
// really uses has agent accounts on it, so Delete refused every time. An agent
// account is routing information, and an order copies the name and number it
// used, so the cascade costs the record nothing. Money history is the only bar.
$held=$mkGateway('Held'); $heldAccount=$mkAccount($agent,$held);
$send('DELETE','/admin/payment-gateways/'.$held->id);
check('the gateway is deleted', PaymentGateway::find($held->id)===null, 'the old strict guard is back');
check('and its routing account goes with it', AgentBankAccount::find($heldAccount->id)===null);

echo "\n— none of it is reachable as a player —\n";
$as($player);
$target=$mkAccount($agent,$gateway);
foreach ([['/admin/agent-bank-info/'.$target->id,'payment account'],['/admin/agents/'.$agent->id,'agent'],['/admin/payment-gateways/'.$gateway->id,'gateway']] as [$uri,$what]) {
    $res=$send('DELETE',$uri);
    check("a player deleting a $what is refused", in_array($res->getStatusCode(),[403,404,302],true) && ! str_contains((string) $res->headers->get('Location',''),'admin/agent'), 'status '.$res->getStatusCode());
}
check('and everything survived the attempt', AgentBankAccount::find($target->id)!==null && Agent::find($agent->id)!==null && PaymentGateway::find($gateway->id)!==null);

echo "\n— passwords: the only \"view\" there can be —\n";
$as($admin);
$stored=(string) Agent::find($agent->id)->password;
check('an agent password is stored as a bcrypt hash', str_starts_with($stored,'$2y$'), substr($stored,0,7));
check('the hash is not the password', $stored !== 'secret-pass-1234');
check('it can only be checked, not read back', Hash::check('secret-pass-1234',$stored) && ! Hash::check('wrong',$stored));
$adminPage=(string)$http->handle(tap(Request::create('https://localhost/admin/agents','GET'), fn($r)=>$r->setLaravelSession($session)))->getContent();
check('no hash is printed on the agent list', ! str_contains($adminPage,$stored));
check('and no password either', ! str_contains($adminPage,'secret-pass-1234'));

echo "\n— so the reveal control is shipped to every screen that takes one —\n";
check('the admin loads it', str_contains($adminPage,'/js/password-reveal.js') && str_contains($adminPage,'/css/password-reveal.css'));
$create=(string)$http->handle(tap(Request::create('https://localhost/admin/agents/create','GET'), fn($r)=>$r->setLaravelSession($session)))->getContent();
check('the agent create form has a password box for it to decorate', str_contains($create,'type="password"'));
Auth::logout(); $s2=$app['session']->driver(); $s2->start();
$login=(string)$http->handle(tap(Request::create('https://localhost/login','GET'), fn($r)=>$r->setLaravelSession($s2)))->getContent();
check('the player login loads it too', str_contains($login,'/js/password-reveal.js'));
check('and still posts a real password field', str_contains($login,'type="password"'));
$agentLogin=(string)$http->handle(tap(Request::create('https://localhost/agent/login','GET'), fn($r)=>$r->setLaravelSession($s2)))->getContent();
check('the agent portal login loads it', str_contains($agentLogin,'/js/password-reveal.js'));
check('the script file is on disk', is_file(public_path('js/password-reveal.js')) && is_file(public_path('css/password-reveal.css')));

echo "\n— and the control behaves, driven against a DOM —\n";
// There is no browser on this machine, so the shipped file is run as written
// against a minimal document. Skipped rather than failed where node is absent.
$node=trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "  --   node is not installed here; tests/password_reveal.node.js not run\n";
} else {
    $out=(string) shell_exec(escapeshellarg($node).' '.escapeshellarg(base_path('tests/password_reveal.node.js')).' 2>&1');
    preg_match('/(\d+) passed, (\d+) failed/', $out, $m);
    check('the reveal control passes all '.($m[1] ?? '?').' of its own checks', ($m[2] ?? '1') === '0', trim($out));
}

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } }
exit($fail?1:0);
