<?php

use App\Models\Agent;
use App\Models\AgentBankAccount;
use App\Models\AgentOrder;
use App\Models\DepositProof;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/*
 * The owner's 28 Oct 2026 list: payment screenshots on deposits, the player
 * choosing who pays a withdrawal, agents hearing about both on Telegram, and
 * a float floor so an agent is stopped before reaching zero.
 */

const AF_BOT_TOKEN = '123456789:AAHtestTokenForAgentFeatures_abcdef';

function afGateway(string $code = 'af-bank'): PaymentGateway
{
    return PaymentGateway::query()->firstOrCreate(['code' => $code], [
        'name' => 'AF Bank '.$code, 'type' => 'bank', 'supports_deposit' => true, 'supports_withdrawal' => true, 'active' => true,
    ]);
}

function afAgent(string $nickname, float $balance = 10000, ?string $chatId = '555001'): Agent
{
    return Agent::query()->create([
        'first_name' => $nickname, 'last_name' => 'Agent', 'nickname' => $nickname,
        'phone' => '0922'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
        'password' => 'agent-pass-1', 'active' => true, 'balance' => $balance, 'telegram_chat_id' => $chatId,
    ]);
}

function afAccount(Agent $agent, PaymentGateway $gateway): AgentBankAccount
{
    return AgentBankAccount::query()->create([
        'agent_id' => $agent->id, 'payment_gateway_id' => $gateway->id,
        'account_name' => $agent->nickname.' Account', 'account_number' => '1000'.$agent->id.$gateway->id.'777', 'active' => true,
    ]);
}

function afBot(): void
{
    test()->setting('telegram_bot_token_encrypted', Crypt::encryptString(AF_BOT_TOKEN));
    test()->setting('telegram_bot_username', 'vamoseone_bot');
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
}

function afScreenshot(): UploadedFile
{
    // A real PNG, 900x1800 like a phone screenshot.
    $image = imagecreatetruecolor(900, 1800);
    imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 220));
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    imagedestroy($image);
    $path = tempnam(sys_get_temp_dir(), 'proof').'.png';
    file_put_contents($path, $png);

    return new UploadedFile($path, 'transfer.png', 'image/png', null, true);
}

beforeEach(function () {
    config(['services.telegram.bot_token' => null, 'services.telegram.bot_username' => null]);
    foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_USERNAME', 'TELEGRAM_WEBHOOK_SECRET'] as $key) {
        putenv($key);
        unset($_SERVER[$key], $_ENV[$key]);
    }
});

/* ---------------- 1. Payment screenshots ---------------- */

it('stores a payment screenshot with the deposit, re-encoded and shrunk, and tells the agent', function () {
    afBot();
    $player = player(0);
    $agent = afAgent('Abel');
    $account = afAccount($agent, afGateway());

    test()->actingAs($player)->post('/player/wallet/deposits', [
        'amount' => 500, 'agent_bank_account_id' => $account->id, 'reference' => 'FT-PROOF-1', 'proof' => afScreenshot(),
    ])->assertRedirect('/player/wallet?tab=deposit');

    $order = AgentOrder::query()->firstOrFail();
    $proof = DepositProof::query()->where('agent_order_id', $order->id)->firstOrFail();
    expect($proof->mime)->toBe('image/jpeg')
        ->and($proof->height)->toBe(1600)
        ->and($proof->width)->toBe(800);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
        && $request['chat_id'] === '555001'
        && str_contains($request['text'], 'New deposit to confirm')
        && str_contains($request['text'], 'screenshot'));
});

it('lets the player add a screenshot later, and shows it only to the player, the assigned agent and an admin', function () {
    afBot();
    $player = player(0);
    $agent = afAgent('Bekele');
    $account = afAccount($agent, afGateway());
    test()->actingAs($player)->post('/player/wallet/deposits', ['amount' => 500, 'agent_bank_account_id' => $account->id, 'reference' => 'FT-LATER-1']);
    $order = AgentOrder::query()->firstOrFail();

    test()->actingAs($player)->post('/player/wallet/deposits/'.$order->id.'/proof', ['proof' => afScreenshot()])
        ->assertRedirect('/player/wallet?tab=deposit')->assertSessionHas('status');

    test()->actingAs($player)->get('/player/wallet/deposits/'.$order->id.'/proof')
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');

    test()->actingAs($agent, 'agent')->get('/agent/deposit-order/'.$order->id.'/proof')->assertOk();
    test()->actingAs(afAgent('Other', 10000, null), 'agent')->get('/agent/deposit-order/'.$order->id.'/proof')->assertNotFound();
    test()->actingAs(player(0))->get('/player/wallet/deposits/'.$order->id.'/proof')->assertNotFound();
    test()->actingAs(User::factory()->admin()->create())->get('/admin/agent-orders/'.$order->id.'/deposit-proof')->assertOk();

    // The agent's list links to it.
    test()->actingAs($agent, 'agent')->get('/agent/deposit-order')->assertSee('View payment screenshot');
});

it('refuses a file that is not a picture and still keeps the deposit', function () {
    $player = player(0);
    $account = afAccount(afAgent('Chala', 10000, null), afGateway());

    test()->actingAs($player)->post('/player/wallet/deposits', [
        'amount' => 500, 'agent_bank_account_id' => $account->id, 'reference' => 'FT-BAD-1',
        'proof' => UploadedFile::fake()->create('statement.pdf', 20, 'application/pdf'),
    ])->assertSessionHasErrors('proof');

    // A JPG that is not really an image: the deposit stands, the player is told.
    $fake = UploadedFile::fake()->create('fake.jpg', 10, 'image/jpeg');
    test()->actingAs($player)->post('/player/wallet/deposits', [
        'amount' => 500, 'agent_bank_account_id' => $account->id, 'reference' => 'FT-BAD-2', 'proof' => $fake,
    ]);
    expect(AgentOrder::query()->where('reference', 'FT-BAD-2')->exists())->toBeTrue()
        ->and(DepositProof::available() ? DepositProof::query()->count() : 0)->toBe(0);
});

/* ---------------- 3. The player chooses who pays a withdrawal ---------------- */

it('assigns a withdrawal to the agent the player chose and tells that agent', function () {
    afBot();
    $this->setting('registration_email_mode', 'off');
    $gateway = afGateway();
    $busy = afAgent('Dawit', 10000, '555100');
    $chosen = afAgent('Eden', 10000, '555200');
    afAccount($busy, $gateway);
    afAccount($chosen, $gateway);
    $player = player(500, 500);

    test()->actingAs($player)->get('/player/wallet?tab=withdraw')->assertOk()->assertSee('Choose Agent')->assertSee('Eden');

    test()->actingAs($player)->post('/player/wallet/withdrawals', [
        'amount' => 100, 'payment_gateway_id' => $gateway->id, 'agent_id' => $chosen->id,
        'account_name' => 'Player One', 'account_number' => '0911000001',
    ])->assertRedirect('/player/wallet?tab=withdraw');

    $order = AgentOrder::query()->where('type', 'withdrawal')->firstOrFail();
    expect($order->agent_id)->toBe($chosen->id);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage') && $request['chat_id'] === '555200' && str_contains($request['text'], 'New withdrawal to pay'));
    Http::assertNotSent(fn ($request) => ($request['chat_id'] ?? null) === '555100');
});

it('refuses an agent who does not pay out with the chosen method', function () {
    $this->setting('registration_email_mode', 'off');
    $gateway = afGateway('af-one');
    $elsewhere = afAgent('Fikru', 10000, null);
    afAccount($elsewhere, afGateway('af-two'));
    afAccount(afAgent('Gete', 10000, null), $gateway);
    $player = player(500, 500);

    test()->actingAs($player)->post('/player/wallet/withdrawals', [
        'amount' => 100, 'payment_gateway_id' => $gateway->id, 'agent_id' => $elsewhere->id,
        'account_name' => 'Player One', 'account_number' => '0911000001',
    ])->assertSessionHasErrors('agent_id');

    expect(AgentOrder::query()->count())->toBe(0)
        ->and((float) $player->fresh()->credits)->toBe(500.0);
});

/* ---------------- 7. The float floor ---------------- */

it('refuses a deposit approval that would take the agent below the minimum float, and warns on the way down', function () {
    afBot();
    $this->setting('agent_min_float', 1000);
    $this->setting('agent_low_float_warning', 2500);
    $agent = afAgent('Hana', 3000, '555300');
    $account = afAccount($agent, afGateway());
    $player = player(0);

    // 3000 - 800 = 2200: allowed, and it crosses the 2500 warning line.
    test()->actingAs($player)->post('/player/wallet/deposits', ['amount' => 800, 'agent_bank_account_id' => $account->id, 'reference' => 'FT-FLOAT-1']);
    $first = AgentOrder::query()->where('reference', 'FT-FLOAT-1')->firstOrFail();
    test()->actingAs($agent, 'agent')->patch('/agent/deposit-order/'.$first->id, ['status' => 'success'])->assertSessionHasNoErrors();
    expect((float) $agent->fresh()->balance)->toBe(2200.0);
    Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === '555300' && str_contains($request['text'], 'float is running low'));

    // 2200 - 1500 = 700, below the 1000 minimum: refused, nothing moves.
    test()->actingAs($player)->post('/player/wallet/deposits', ['amount' => 1500, 'agent_bank_account_id' => $account->id, 'reference' => 'FT-FLOAT-2']);
    $second = AgentOrder::query()->where('reference', 'FT-FLOAT-2')->firstOrFail();
    test()->actingAs($agent, 'agent')->patch('/agent/deposit-order/'.$second->id, ['status' => 'success'])->assertSessionHasErrors('status');
    expect((float) $agent->fresh()->balance)->toBe(2200.0)
        ->and($second->fresh()->status)->toBe('pending')
        ->and((float) $player->fresh()->credits)->toBe(800.0);

    // The dashboard says so.
    // A fresh copy: the guard keeps the object it was given, and a real request loads it anew.
    test()->actingAs($agent->fresh(), 'agent')->get('/agent')->assertSee('Your float is low');
});

it('applies the same floor when an administrator approves on the agent\'s behalf', function () {
    $this->setting('agent_min_float', 1000);
    $agent = afAgent('Iman', 1500, null);
    $account = afAccount($agent, afGateway());
    $player = player(0);
    test()->actingAs($player)->post('/player/wallet/deposits', ['amount' => 800, 'agent_bank_account_id' => $account->id, 'reference' => 'FT-ADMIN-1']);
    $order = AgentOrder::query()->firstOrFail();

    test()->actingAs(User::factory()->admin()->create())->from('/admin/agent-orders')
        ->patch('/admin/agent-orders/'.$order->id.'/status', ['status' => 'success'])
        ->assertSessionHasErrors('status');

    expect((float) $agent->fresh()->balance)->toBe(1500.0);
});

it('turns the floor off at zero', function () {
    $this->setting('agent_min_float', 0);
    $agent = afAgent('Jemal', 800, null);
    $account = afAccount($agent, afGateway());
    $player = player(0);
    test()->actingAs($player)->post('/player/wallet/deposits', ['amount' => 800, 'agent_bank_account_id' => $account->id, 'reference' => 'FT-ZERO-1']);
    $order = AgentOrder::query()->firstOrFail();

    test()->actingAs($agent, 'agent')->patch('/agent/deposit-order/'.$order->id, ['status' => 'success'])->assertSessionHasNoErrors();
    expect((float) $agent->fresh()->balance)->toBe(0.0);
});

it('lets an administrator set the float levels and the public site address', function () {
    $admin = User::factory()->admin()->create();

    test()->actingAs($admin)->get('/admin/settings?tab=agent')->assertOk()
        ->assertSee('name="agent_min_float"', false)->assertSee('name="agent_low_float_warning"', false);
    test()->actingAs($admin)->get('/admin/settings?tab=system')->assertOk()
        ->assertSee('name="public_site_url"', false);

    // A malformed address is refused rather than breaking every link the bot sends.
    expect(\App\Support\SiteUrl::clean('vamosonebet.com'))->toBe('');
});
