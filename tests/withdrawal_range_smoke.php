<?php
/*
 * The withdrawal form has to offer exactly what the server will accept.
 *
 * A player holding 23,000 ETB withdrawable typed 4,000 and the browser answered
 * "Value must be 30." — the platform MINIMUM, quoted as though it were the only
 * legal amount. The field carried min="30" max="30", because the template built
 * its ceiling as
 *
 *     max($minimum, min($balance, $platformMax, $cap ?? 30000))
 *
 * and that outer max() raises the ceiling back to the minimum whenever it falls
 * below it. Any limit reading zero — a cleared Max Withdraw Amount, a cap saved
 * as 0 — collapsed the field onto a single impossible value, and nothing on the
 * page said which limit had done it.
 *
 * Zero is where the two surfaces disagreed: FinancialRuleService::assertRange()
 * treats a maximum of zero as NO maximum, so the server would have accepted the
 * 4,000 the form refused.
 *
 * Run: php tests/withdrawal_range_smoke.php   (local .env database, rolled back)
 */
$ROOT = $argv[1] ?? dirname(__DIR__); require "$ROOT/vendor/autoload.php"; $app=require "$ROOT/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{User,PaymentGateway,PlatformSetting};
use App\Services\FinancialRuleService;
use Illuminate\Http\Request; use Illuminate\Support\Facades\{DB,Auth};
use Illuminate\Validation\ValidationException;
$http=$app->make(Illuminate\Contracts\Http\Kernel::class); $pass=0;$fail=0;
function check($l,$ok,$d=''){global $pass,$fail; if($ok){$pass++;echo "  ok   $l\n";}else{$fail++;echo "  FAIL $l — $d\n";}}
$rules=app(FinancialRuleService::class);

/** Settings are read through a static cache, so every write has to flush it. */
$set=function(array $pairs){
    foreach($pairs as $key=>$value){
        DB::table('platform_settings')->updateOrInsert(['key'=>$key],['value'=>(string)$value]);
    }
    PlatformSetting::flushValueCache();
};

try {
DB::beginTransaction();

// Daily limits are a fourth rule the form does not draw — the server names the
// remaining allowance in its own message — so they stay off for these checks.
$set([
    'min_withdraw_amount'=>30, 'max_withdraw_amount'=>30000,
    'daily_max_withdraw'=>0, 'currency_code'=>'ETB',
]);
PaymentGateway::query()->firstOrCreate(
    ['code'=>'smoke-bank'],
    ['name'=>'Smoke Bank','type'=>'bank','account_label'=>'Account number','supports_deposit'=>true,'supports_withdrawal'=>true,'active'=>true]
);

$player=User::forceCreate(['name'=>'W','username'=>'w'.uniqid(),'phone'=>'0911'.random_int(100000,999999),'password'=>'x','role'=>'player','active'=>true,'credits'=>23000,'withdrawable_balance'=>23000]);
$session=$app['session']->driver();

/** The withdraw tab as the player sees it. */
$page=function(string $tab='withdraw') use($app,$http,$player){
    Auth::login($player->fresh());
    $s=$app['session']->driver(); $s->start();
    $r=Request::create('https://localhost/player/wallet','GET',['tab'=>$tab]);
    $r->setLaravelSession($s);
    return (string)$http->handle($r)->getContent();
};
/** Would the server take this amount? */
$serverTakes=function(float $amount) use($rules,$player){
    try { $rules->assertWithdrawalAllowed($player->fresh(), $amount); return true; }
    catch (ValidationException) { return false; }
};
/** Would the field let it through? */
$fieldTakes=function(float $amount) use($rules,$player){
    $r=$rules->withdrawalRange($player->fresh());
    return $r['open'] && $amount >= $r['minimum'] && $amount <= $r['maximum'];
};

echo "— the reported case: 23,000 withdrawable, Max Withdraw Amount left empty —\n";
$set(['max_withdraw_amount'=>'']);
$range=$rules->withdrawalRange($player->fresh());
check('the band opens', $range['open']===true);
check('the ceiling is the balance, not zero', $range['maximum']===23000.0, var_export($range['maximum'],true));
check('and the balance is named as what binds it', $range['binding']==='balance', $range['binding']);
$html=$page();
check('the field offers the whole balance', str_contains($html,'max="23000"'), 'max attribute not found');
check('min and max no longer meet at 30', ! preg_match('/min="30"[^>]*max="30"/', $html), 'the collapsed range is still rendered');
check('4,000 passes the field', $fieldTakes(4000));
check('and the server takes the same 4,000', $serverTakes(4000), 'the form and the validator still disagree');

echo "\n— a maximum genuinely set to 30 is stated, not hidden —\n";
$set(['max_withdraw_amount'=>30]);
$range=$rules->withdrawalRange($player->fresh());
check('the band is 30 to 30', $range['open'] && $range['minimum']===30.0 && $range['maximum']===30.0);
check('the platform limit is named', $range['binding']==='platform', $range['binding']);
check('the page says so in words', str_contains($page(),'Request ETB 30 &ndash; ETB 30') || str_contains($page(),'Request ETB 30 – ETB 30'), 'the band is not written out');
check('4,000 is refused by both', ! $fieldTakes(4000) && ! $serverTakes(4000));

echo "\n— a maximum below the minimum closes the form and says why —\n";
$set(['max_withdraw_amount'=>10]);
$range=$rules->withdrawalRange($player->fresh());
check('the band is closed', $range['open']===false);
check('the heading does not blame the balance', $range['heading']==='Withdrawals unavailable', $range['heading']);
check('the note names the maximum', str_contains($range['note'],'maximum withdrawal is set to 10.00 ETB'), $range['note']);
$html=$page();
check('the note reaches the page', str_contains($html,'maximum withdrawal is set to 10.00 ETB'));
check('the amount field is disabled', preg_match('/wd-amount-input[^>]*disabled/', $html)===1, 'the field is still typeable');
check('and so is the submit', str_contains($html,'wd-submit-btn') && preg_match('/wd-submit-btn[^>]*disabled/', $html)===1);
check('no bounds are written at all', ! preg_match('/name="amount"[^>]*min="30"/', $html), 'a min survived on a closed form');

echo "\n— a cap of zero is the cap's fault, not the balance's —\n";
$set(['max_withdraw_amount'=>30000]);
$player->forceFill(['withdrawal_cap'=>0])->save();
$range=$rules->withdrawalRange($player->fresh());
check('closed', $range['open']===false);
check('the cap is named', $range['binding']==='cap', $range['binding']);
check('and the note says an administrator can raise it', str_contains($range['note'],'withdrawal cap of 0.00 ETB') && str_contains($range['note'],'raise it'), $range['note']);
$player->forceFill(['withdrawal_cap'=>null])->save();

echo "\n— a balance under the minimum keeps its own wording —\n";
$player->forceFill(['credits'=>10,'withdrawable_balance'=>10])->save();
$range=$rules->withdrawalRange($player->fresh());
check('closed', $range['open']===false);
check('the heading is about the balance', $range['heading']==='No withdrawable balance', $range['heading']);
check('and locked funds are still explained', str_contains($range['note'],'Bonus or locked funds'), $range['note']);
$player->forceFill(['credits'=>23000,'withdrawable_balance'=>23000])->save();

echo "\n— a fractional ceiling is stated to the cent, not rounded past itself —\n";
$set(['max_withdraw_amount'=>30000]);
$player->forceFill(['credits'=>'23000.75','withdrawable_balance'=>'23000.75'])->save();
$range=$rules->withdrawalRange($player->fresh());
check('the ceiling keeps its cents', $range['maximum']===23000.75, var_export($range['maximum'],true));
$html=$page();
check('and the field does too', str_contains($html,'max="23000.75"'), 'max attribute rounded');
check('the hint states it exactly', str_contains($html,'ETB 23,000.75'), 'the band was rounded in the hint');
check('a rounded-up ceiling is nowhere on the page', ! str_contains($html,'ETB 23,001'), 'the page offers more than the field takes');
check('while a whole minimum stays whole', str_contains($html,'Request ETB 30 '), 'the minimum grew decimals it does not need');
check('the header does not round it up either', ! str_contains($html,'>23,001<') && str_contains($html,'23,000.75'), 'the pill and the page disagree');
// The pill refreshes from this endpoint between page loads, so it has to round
// the same way the markup did or the figure changes on its own.
Auth::login($player->fresh()); $bs=$app['session']->driver(); $bs->start();
$br=Request::create('https://localhost/player/balance','GET'); $br->setLaravelSession($bs);
$json=json_decode((string)$http->handle($br)->getContent(), true);
check('and neither does the endpoint that refreshes it', ($json['pill'] ?? null)==='23,000.75' && ($json['formatted'] ?? null)==='ETB 23,000.75', json_encode($json));
$player->forceFill(['credits'=>23000,'withdrawable_balance'=>23000])->save();

echo "\n— the field and the validator agree, state for state —\n";
// Each row: platform maximum, cap, withdrawable balance, then the amounts to try.
$cases=[
    ['', null, 23000],      // the reported production state
    [30000, null, 23000],   // the intended one
    [30000, 5000, 23000],   // a capped player
    [30000, null, 500],     // a thin balance
    [100, null, 23000],     // a maximum tighter than the balance
    [30, null, 23000],      // minimum and maximum meeting, honestly
];
$drift=[];
foreach($cases as [$max,$cap,$balance]){
    $set(['max_withdraw_amount'=>$max]);
    $player->forceFill(['withdrawal_cap'=>$cap,'credits'=>$balance,'withdrawable_balance'=>$balance])->save();
    foreach([29,30,100,4000,5000,23000,23001,30001] as $amount){
        if($fieldTakes((float)$amount) !== $serverTakes((float)$amount)){
            $drift[]=sprintf('max=%s cap=%s balance=%s amount=%s (field %s, server %s)',
                var_export($max,true), var_export($cap,true), $balance, $amount,
                $fieldTakes((float)$amount)?'yes':'no', $serverTakes((float)$amount)?'yes':'no');
        }
    }
}
check('no amount is offered by one and refused by the other', $drift===[], implode('; ', array_slice($drift,0,6)));

echo "\n— the admin says what a cap of zero actually does —\n";
// The cap is what closed a real player's withdraw form, and the admin showed it
// as "ETB 0" among the other limits — a figure, not a state.
$admin=User::forceCreate(['name'=>'A','username'=>'a'.uniqid(),'phone'=>'0912'.random_int(100000,999999),'password'=>'x','role'=>'admin','active'=>true]);
$memberPage=function() use($app,$http,$admin,$player){
    Auth::logout(); Auth::login($admin); $s=$app['session']->driver(); $s->start();
    $r=Request::create('https://localhost/admin/members/'.$player->id,'GET'); $r->setLaravelSession($s);
    return (string)$http->handle($r)->getContent();
};
// Scoped to the Withdrawal Cap row: the other two caps are unset throughout, so
// searching the whole page would find their wording and prove nothing.
$row=function(string $html) {
    $i=strpos($html,'Withdrawal Cap');
    if ($i===false) { return ''; }
    $j=strpos($html,'</div>',$i);
    return substr($html,$i,($j===false ? 220 : $j-$i));
};
$player->forceFill(['withdrawal_cap'=>0])->save();
$cell=$row($memberPage());
check('the row is found at all', $cell !== '');
check('a zero cap says it blocks withdrawals', str_contains($cell,'blocks all withdrawals'), $cell);
check('and does not read as unset', ! str_contains($cell,'No cap'), $cell);
$player->forceFill(['withdrawal_cap'=>5000])->save();
$cell=$row($memberPage());
check('a real cap is just the figure', str_contains($cell,'ETB 5,000') && ! str_contains($cell,'blocks all'), $cell);
$player->forceFill(['withdrawal_cap'=>null])->save();
$html=$memberPage();
check('an unset cap reads as no cap', str_contains($row($html),'No cap'), $row($html));
check('and the edit field says blank is how you get one', str_contains($html,'Blank for no cap'));

// The remedy itself, end to end: an operator clearing that box has to actually
// store null and reopen the band, or the advice on the player's screen is wrong.
// The sweep above left the platform maximum on its last case; state it again so
// the reopened ceiling below can only be the balance.
$set(['max_withdraw_amount'=>30000]);
$player->forceFill(['withdrawal_cap'=>0,'credits'=>23000,'withdrawable_balance'=>23000])->save();
check('a zero cap closes the band', $rules->withdrawalRange($player->fresh())['open']===false);
Auth::logout(); Auth::login($admin); $es=$app['session']->driver(); $es->start();
$edit=Request::create('https://localhost/admin/members/'.$player->id,'PATCH',[
    '_token'=>$es->token(),'first_name'=>'W','last_name'=>'','phone'=>$player->phone,
    'withdrawal_cap'=>'','transfer_cap'=>'','deposit_cap'=>'','active'=>'1',
]);
$edit->setLaravelSession($es);
$res=$http->handle($edit);
check('saving with the box empty is accepted', $res->getStatusCode()===302, 'status '.$res->getStatusCode());
check('the cap is stored as not set, not as zero', $player->fresh()->withdrawal_cap===null, var_export($player->fresh()->withdrawal_cap,true));
check('and the band reopens to the balance', $rules->withdrawalRange($player->fresh())['maximum']===23000.0);
Auth::logout(); Auth::login($player->fresh());

echo "\n— the deposit field reads zero the same way —\n";
$set(['min_deposit_amount'=>50,'max_deposit_amount'=>0]);
$deposit=$page('deposit');
// Without this the two checks below would pass on a page that never rendered
// the deposit flow at all.
check('the deposit field is on the page', str_contains($deposit,'data-deposit-amount'));
check('no max is written when the limit is off', ! preg_match('/data-deposit-amount[^>]*max=/', $deposit) && ! preg_match('/max="0"[^>]*data-deposit-amount/', $deposit), 'max="0" would block every deposit');
check('and the hint says there is none', str_contains($deposit,'No maximum'));

echo "\n$pass passed, $fail failed\n";
} catch (\Throwable $e) {
    $fail++;
    echo "\n  FATAL ".get_class($e).': '.$e->getMessage()."\n        at ".$e->getFile().':'.$e->getLine()."\n";
    echo "\n$pass passed, $fail failed\n";
} finally { if (DB::transactionLevel() > 0) { DB::rollBack(); } PlatformSetting::flushValueCache(); }
exit($fail?1:0);
