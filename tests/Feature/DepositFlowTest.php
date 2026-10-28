<?php

use App\Models\Agent;
use App\Models\AgentBankAccount;
use App\Models\AgentOrder;
use App\Models\PaymentGateway;
use App\Models\User;

/*
 * A deposit is a promise the player makes to an agent: the page must keep
 * showing where the money goes until the agent confirms it, say how long
 * that usually takes, and tell the player in place when a submission is
 * refused.
 */

function depositAccount(): AgentBankAccount
{
    // The migrations seed the real banks, so this one is a test bank of its own.
    $gateway = PaymentGateway::query()->create(['name' => 'Test Bank', 'code' => 'test-bank', 'type' => 'bank', 'supports_deposit' => true, 'supports_withdrawal' => true, 'active' => true]);
    $agent = Agent::query()->create(['first_name' => 'Abel', 'last_name' => 'Agent', 'nickname' => 'Abel', 'phone' => '0922000001', 'password' => 'agent-pass-1', 'active' => true]);

    return AgentBankAccount::query()->create(['agent_id' => $agent->id, 'payment_gateway_id' => $gateway->id, 'account_name' => 'Abel Agent', 'account_number' => '1000123456789', 'active' => true]);
}

it('creates a pending deposit and keeps the account details on the wallet page', function () {
    $this->setting('deposit_review_minutes', 20);
    $user = player(0);
    $account = depositAccount();

    test()->actingAs($user)->post('/player/wallet/deposits', [
        'amount' => 500,
        'agent_bank_account_id' => $account->id,
        'reference' => 'FT24123456',
    ])->assertRedirect('/player/wallet?tab=deposit')
        ->assertSessionHas('deposit_submitted', true)
        ->assertSessionHas('status', fn ($status) => str_contains($status, '20 minutes'));

    $order = AgentOrder::query()->where('type', 'deposit')->first();
    expect($order->status)->toBe('pending')
        ->and((float) $order->amount)->toBe(500.0)
        ->and($order->account_number)->toBe('1000123456789')
        ->and((float) $user->fresh()->credits)->toBe(0.0);   // nothing is credited until the agent confirms

    // After a reload the page still shows where the money goes, with a copy button and the review time.
    $page = test()->actingAs($user)->get('/player/wallet?tab=deposit')->assertOk();
    $page->assertSee('1000123456789')
        ->assertSee('data-copy="1000123456789"', false)
        ->assertSee('FT24123456')
        ->assertSee('20 minutes');
});

it('refuses a bank reference that was already submitted and hands the dialog what it needs to reopen', function () {
    $user = player(0);
    $account = depositAccount();
    $payload = ['amount' => 500, 'agent_bank_account_id' => $account->id, 'reference' => 'FT24123456'];

    test()->actingAs($user)->post('/player/wallet/deposits', $payload);
    test()->actingAs($user)->from('/player/wallet?tab=deposit')->post('/player/wallet/deposits', $payload + ['idempotency_key' => (string) \Illuminate\Support\Str::uuid()])
        ->assertRedirect('/player/wallet?tab=deposit')
        ->assertSessionHasErrors('reference');

    expect(AgentOrder::query()->count())->toBe(1);

    $page = test()->actingAs($user)->get('/player/wallet?tab=deposit')->assertOk();
    $page->assertSee('data-deposit-error=', false)
        ->assertSee('data-old-account="'.$account->id.'"', false)
        ->assertSee('data-old-reference="FT24123456"', false);
});

it('lets the player cancel a pending deposit', function () {
    $user = player(0);
    $account = depositAccount();
    test()->actingAs($user)->post('/player/wallet/deposits', ['amount' => 500, 'agent_bank_account_id' => $account->id, 'reference' => 'FT1']);
    $order = AgentOrder::query()->first();

    test()->actingAs($user)->patch('/player/wallet/deposits/'.$order->id.'/cancel')->assertRedirect();
    expect($order->fresh()->status)->toBe('cancelled');

    // Another player cannot touch it.
    test()->actingAs(player(0))->patch('/player/wallet/deposits/'.$order->id.'/cancel')->assertNotFound();
});

it('tells the player who pays a withdrawal and how long it usually takes', function () {
    $this->setting('withdrawal_review_hours', 12);
    $this->setting('registration_email_mode', 'off');
    $user = player(500, 500);
    depositAccount();

    test()->actingAs($user)->get('/player/wallet?tab=withdraw')->assertOk()
        ->assertSee('Paid by one of our agents')
        ->assertSee('12 hours')
        ->assertDontSee('Administrator review required');

    test()->actingAs($user)->post('/player/wallet/withdrawals', [
        'amount' => 100,
        'payment_gateway_id' => PaymentGateway::query()->where('code', 'test-bank')->value('id'),
        'account_name' => 'Player One',
        'account_number' => '0911000001',
    ])->assertRedirect('/player/wallet?tab=withdraw')
        ->assertSessionHas('status', fn ($status) => str_contains($status, 'An agent will pay it') && str_contains($status, '12 hours'));

    $user->refresh();
    expect((float) $user->credits)->toBe(400.0)
        ->and((float) $user->withdrawable_balance)->toBe(400.0);
});
