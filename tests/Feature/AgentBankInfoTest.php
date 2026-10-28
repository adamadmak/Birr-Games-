<?php

use App\Models\Agent;
use App\Models\AgentBankAccount;
use App\Models\PaymentGateway;

/* An agent's own payment accounts (Agent portal → Bank Info). */

function bankInfoAgent(): Agent
{
    return Agent::query()->create([
        'first_name' => 'Abel', 'last_name' => 'Agent', 'nickname' => 'Abel', 'phone' => '0955000001',
        'password' => 'agent-pass-1', 'active' => true, 'balance' => 10000,
    ]);
}

function bankInfoGateway(): PaymentGateway
{
    return PaymentGateway::query()->firstOrCreate(['code' => 'bi-bank'], [
        'name' => 'BI Bank', 'type' => 'bank', 'supports_deposit' => true, 'supports_withdrawal' => true, 'active' => true,
    ]);
}

it('adds an account, and answers a repeat with a message instead of a server error', function () {
    $agent = bankInfoAgent();
    $gateway = bankInfoGateway();
    $form = ['payment_gateway_id' => $gateway->id, 'account_name' => 'Abel Agent', 'account_number' => '1000 2000 3000'];

    test()->actingAs($agent, 'agent')->from('/agent/bank-info')->post('/agent/bank-info', $form)
        ->assertRedirect('/agent/bank-info')->assertSessionHasNoErrors();
    expect(AgentBankAccount::query()->where('agent_id', $agent->id)->value('account_number'))->toBe('100020003000');

    test()->actingAs($agent, 'agent')->from('/agent/bank-info')->post('/agent/bank-info', $form)
        ->assertRedirect('/agent/bank-info')->assertSessionHasErrors('account_number');
    expect(AgentBankAccount::query()->count())->toBe(1);
});

it('switches a disabled account back on when it is added again', function () {
    $agent = bankInfoAgent();
    $gateway = bankInfoGateway();
    AgentBankAccount::query()->create(['agent_id' => $agent->id, 'payment_gateway_id' => $gateway->id, 'account_name' => 'Old', 'account_number' => '555', 'active' => false]);

    test()->actingAs($agent, 'agent')->post('/agent/bank-info', ['payment_gateway_id' => $gateway->id, 'account_name' => 'Abel Agent', 'account_number' => '555'])
        ->assertSessionHasNoErrors();

    $account = AgentBankAccount::query()->sole();
    expect($account->active)->toBeTrue()->and($account->account_name)->toBe('Abel Agent');
});

it('refuses a payment method that is switched off', function () {
    $agent = bankInfoAgent();
    $gateway = bankInfoGateway();
    $gateway->update(['active' => false]);

    test()->actingAs($agent, 'agent')->post('/agent/bank-info', ['payment_gateway_id' => $gateway->id, 'account_name' => 'A', 'account_number' => '1'])
        ->assertSessionHasErrors('payment_gateway_id');
});
