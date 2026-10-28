<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\WinhouseTransaction;
use App\Services\AgentShop;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * The counter: walk-in betting through an agent (App\Services\AgentShop).
 * Float moves into the agent's own counter account and back, the agent opens
 * the sportsbook as that account, and a winning counter ticket is paid once.
 */

beforeEach(function () {
    config([
        'services.winhouse.iframe_key' => 'iframe-key-for-tests',
        'services.winhouse.api_key' => 'api-key-for-tests',
        'services.telegram.bot_token' => null,
    ]);
    Sleep::fake();
    Http::preventStrayRequests();
});

function counterAgent(string $nickname, float $balance = 10000): Agent
{
    return Agent::query()->create([
        'first_name' => $nickname, 'last_name' => 'Agent', 'nickname' => $nickname,
        'phone' => '0933'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
        'password' => 'agent-pass-1', 'active' => true, 'balance' => $balance,
    ]);
}

it('creates one counter per agent, with no phone and no way in but the portal', function () {
    $abel = counterAgent('Abel');
    test()->actingAs($abel, 'agent')->get('/agent/counter')->assertOk()->assertSee('Counter balance');
    test()->actingAs($abel, 'agent')->get('/agent/counter')->assertOk();

    $counters = User::query()->where('member_type', AgentShop::MEMBER_TYPE)->get();
    expect($counters)->toHaveCount(1)
        ->and($counters[0]->username)->toBe('shop_agent_'.$abel->id)
        ->and($counters[0]->phone)->toBeNull();
});

it('moves float to the counter and back, keeping the ledger', function () {
    $abel = counterAgent('Abel', 10000);

    test()->actingAs($abel, 'agent')->post('/agent/counter/top-up', ['amount' => 3000])->assertSessionHasNoErrors();
    $counter = app(AgentShop::class)->account($abel)->fresh();
    expect((float) $abel->fresh()->balance)->toBe(7000.0)
        ->and((float) $counter->credits)->toBe(3000.0)
        ->and((float) $counter->withdrawable_balance)->toBe(3000.0);

    test()->actingAs($abel->fresh(), 'agent')->post('/agent/counter/give-back', ['amount' => 1000])->assertSessionHasNoErrors();
    expect((float) $abel->fresh()->balance)->toBe(8000.0)
        ->and((float) $counter->fresh()->credits)->toBe(2000.0);

    // More than the counter holds is refused and nothing moves.
    test()->actingAs($abel->fresh(), 'agent')->post('/agent/counter/give-back', ['amount' => 5000])->assertSessionHasErrors('amount');
    expect((float) $abel->fresh()->balance)->toBe(8000.0);

    expect(\App\Models\WalletTransaction::query()->where('method', 'agent_shop')->sum('amount'))->toEqual(2000);
});

it('applies the float floor to a top-up', function () {
    test()->setting('agent_min_float', '1000');
    $abel = counterAgent('Abel', 1500);

    test()->actingAs($abel, 'agent')->post('/agent/counter/top-up', ['amount' => 800])->assertSessionHasErrors('amount');
    expect((float) $abel->fresh()->balance)->toBe(1500.0);
});

it('opens the sportsbook signed in as the agent\'s own counter', function () {
    $abel = counterAgent('Abel');

    test()->actingAs($abel, 'agent')->post('/agent/counter/open')->assertRedirect('/');
    expect(Auth::guard('web')->user()?->username)->toBe('shop_agent_'.$abel->id);
});

it('keeps the counter out of deposits and withdrawals', function () {
    $abel = counterAgent('Abel');
    $counter = app(AgentShop::class)->account($abel);
    $counter->forceFill(['credits' => 500, 'withdrawable_balance' => 500])->save();

    test()->actingAs($counter)->post('/player/wallet/deposits', ['amount' => 100])->assertSessionHasErrors('amount');
    test()->actingAs($counter)->post('/player/wallet/withdrawals', ['amount' => 100])->assertSessionHasErrors('amount');
    expect((float) $counter->fresh()->credits)->toBe(500.0);
});

it('pays a winning counter ticket once, for what the wallet credited', function () {
    $abel = counterAgent('Abel');
    $bekele = counterAgent('Bekele');
    $counter = app(AgentShop::class)->account($abel);
    WinhouseTransaction::query()->create(['provider_transaction_id' => 'w-1', 'action' => 'win', 'user_id' => $counter->id, 'ticket_id' => 'TK-WIN-1', 'amount' => 625, 'currency' => 'ETB', 'payload_hash' => str_repeat('a', 64)]);

    Http::fake(fn (HttpRequest $request) => Http::response(['total' => 1, 'tickets' => [[
        'ticket_id' => 'TK-WIN-1', 'player_id' => 'player_'.$counter->id, 'status' => 'won', 'stake' => 250,
        'potential_win' => 625, 'payout' => 625, 'currency' => 'ETB', 'placed_at' => '2026-10-28T10:00:00Z', 'selections' => [],
    ]]]));

    test()->actingAs($abel, 'agent')->get('/agent/tickets')->assertOk()
        ->assertSee('TK-WIN-1')
        ->assertSee(route('agent.tickets.pay-out', 'TK-WIN-1'), false);

    // Another agent cannot pay out this counter's ticket.
    test()->actingAs($bekele, 'agent')->post('/agent/tickets/TK-WIN-1/pay-out')->assertSessionHasErrors('ticket');

    test()->actingAs($abel, 'agent')->post('/agent/tickets/TK-WIN-1/pay-out')
        ->assertSessionHasNoErrors()->assertSessionHas('agent_status');
    test()->actingAs($abel, 'agent')->post('/agent/tickets/TK-WIN-1/pay-out')->assertSessionHasErrors('ticket');

    expect(\DB::table('shop_payouts')->where('ticket_id', 'TK-WIN-1')->value('amount'))->toEqual(625);
    test()->actingAs($abel, 'agent')->get('/agent/tickets')->assertOk()->assertSee('Paid out');
});

it('refuses to pay a ticket the counter did not win', function () {
    $abel = counterAgent('Abel');
    app(AgentShop::class)->account($abel);

    test()->actingAs($abel, 'agent')->post('/agent/tickets/TK-LOST-1/pay-out')->assertSessionHasErrors('ticket');
});
