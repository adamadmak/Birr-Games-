<?php

use App\Models\Agent;
use App\Models\AgentOrder;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\WinhouseTransaction;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Bet tickets in the agent portal. An agent sees their own customers' tickets
 * (players with one of their orders), any ticket whose full number they type
 * (the player holding the slip is the authorisation), and nothing else; the
 * receipt prints for any ticket that exists.
 */

beforeEach(function () {
    config([
        'services.winhouse.iframe_key' => 'iframe-key-for-tests',
        'services.winhouse.api_key' => 'api-key-for-tests',
    ]);
    // The client retries a failed call after 400 ms; the tests need not wait.
    Sleep::fake();
    Http::preventStrayRequests();
});

function ticketAgent(string $nickname, string $phone): Agent
{
    return Agent::query()->create(['first_name' => $nickname, 'last_name' => 'Agent', 'nickname' => $nickname, 'phone' => $phone, 'password' => 'agent-pass-1', 'active' => true]);
}

/** A player who has deposited with this agent, which is what makes them a customer. */
function customerOf(Agent $agent, array $attributes = []): User
{
    // The migrations seed the real banks, so this one is a test bank of its own.
    $gateway = PaymentGateway::query()->firstOrCreate(['code' => 'test-bank'], ['name' => 'Test Bank', 'type' => 'bank', 'supports_deposit' => true, 'supports_withdrawal' => true, 'active' => true]);
    $user = player(0, null, $attributes);
    AgentOrder::query()->create(['user_id' => $user->id, 'agent_id' => $agent->id, 'payment_gateway_id' => $gateway->id, 'type' => 'deposit', 'amount' => 100, 'reference' => 'DEP-'.$user->id, 'status' => 'success']);

    return $user;
}

/** A ticket the way WinHouse's tenant API answers it (docs/agent.md §4.7). */
function winhouseTicket(string $id, User $user, array $extra = []): array
{
    return array_merge([
        'ticket_id' => $id, 'player_id' => 'player_'.$user->id, 'username' => 'vamos_player_'.$user->id,
        'status' => 'open', 'type' => 'single', 'placed_at' => '2026-10-27T10:00:00Z', 'settled_at' => null,
        'currency' => 'ETB', 'stake' => 50, 'total_odds' => 2.5, 'bonus' => 0, 'potential_win' => 125, 'payout' => 0,
        'selections' => [[
            'home' => 'Saint George', 'away' => 'Fasil Kenema', 'league' => 'Ethiopian Premier League', 'country' => 'Ethiopia',
            'kickoff' => '2026-10-28T15:00:00Z', 'bet_type' => 'prematch',
            'picks' => [['market' => '1X2', 'pick' => '1', 'odds' => 2.5]],
        ]],
    ], $extra);
}

/** WinHouse's tickets API over these tickets: the list honours player_id, limit and offset; the detail answers by id. */
function fakeWinhouseTickets(array $tickets): void
{
    Http::fake(function (HttpRequest $request) use ($tickets) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (preg_match('#/tenant/api/tickets/([^/]+)$#', $path, $m)) {
            $found = collect($tickets)->firstWhere('ticket_id', rawurldecode($m[1]));

            return $found ? Http::response(['ticket' => $found]) : Http::response(['error' => 'Ticket not found'], 404);
        }

        $list = array_values(array_filter($tickets, fn ($t) => empty($query['player_id']) || $t['player_id'] === $query['player_id']));

        return Http::response([
            'total' => count($list),
            'tickets' => array_slice($list, (int) ($query['offset'] ?? 0), (int) ($query['limit'] ?? 50)),
        ]);
    });
}

it('sends a guest to the agent sign-in', function () {
    test()->get('/agent/tickets')->assertRedirect(route('agent.login'));
    test()->get('/agent/tickets/TK-1/print')->assertRedirect(route('agent.login'));
});

it('lists the agent\'s customers\' tickets and not another agent\'s', function () {
    $abel = ticketAgent('Abel', '0922000001');
    $bekele = ticketAgent('Bekele', '0922000002');
    $mine = customerOf($abel, ['name' => 'Mine Player']);
    $theirs = customerOf($bekele, ['name' => 'Their Player']);
    $stranger = player(0, null, ['name' => 'Stranger Player']);
    fakeWinhouseTickets([
        winhouseTicket('TK-MINE-1', $mine),
        winhouseTicket('TK-THEIRS-2', $theirs),
        winhouseTicket('TK-STRANGER-3', $stranger),
    ]);

    test()->actingAs($abel, 'agent')->get('/agent/tickets')->assertOk()
        ->assertSee('TK-MINE-1')
        ->assertSee('Mine Player')
        ->assertSee(route('agent.tickets.print', ['ticket' => 'TK-MINE-1', 'auto' => 1]), false)
        ->assertDontSee('TK-THEIRS-2')
        ->assertDontSee('Their Player')
        ->assertDontSee('TK-STRANGER-3');

    // A ticket number that only partly matches never reaches past the agent's customers.
    test()->actingAs($abel, 'agent')->get('/agent/tickets?ticket=THEIRS')->assertOk()
        ->assertDontSee('TK-THEIRS-2');

    // Every call names the operator by its iframe key and carries the API key.
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'customer_key=iframe-key-for-tests')
        && $request->hasHeader('Authorization', 'Bearer api-key-for-tests'));
});

it('finds a customer by any spelling of their phone, and nobody else by phone', function () {
    $abel = ticketAgent('Abel', '0922000001');
    $bekele = ticketAgent('Bekele', '0922000002');
    $mine = customerOf($abel, ['phone' => '0911223344']);
    $theirs = customerOf($bekele, ['phone' => '0911556677']);
    fakeWinhouseTickets([winhouseTicket('TK-MINE-1', $mine), winhouseTicket('TK-THEIRS-2', $theirs)]);

    test()->actingAs($abel, 'agent')->get('/agent/tickets?phone='.urlencode('+251 911 223 344'))->assertOk()
        ->assertSee('TK-MINE-1');

    test()->actingAs($abel, 'agent')->get('/agent/tickets?phone=251911556677')->assertOk()
        ->assertDontSee('TK-THEIRS-2')
        ->assertSee('None of your customers has the phone number');
});

it('finds any ticket by its full number, with the other player\'s phone masked', function () {
    $abel = ticketAgent('Abel', '0922000001');
    $stranger = player(0, null, ['name' => 'Stranger Player', 'phone' => '0911998877']);
    fakeWinhouseTickets([winhouseTicket('TK-STRANGER-3', $stranger)]);

    test()->actingAs($abel, 'agent')->get('/agent/tickets?ticket=TK-STRANGER-3')->assertOk()
        ->assertSee('TK-STRANGER-3')
        ->assertSee('Stranger Player')
        ->assertSee('Found by ticket number')
        ->assertDontSee('0911998877')
        ->assertDontSee('911 998 877');
});

it('prints the receipt for a ticket that exists and 404s for one that does not', function () {
    $abel = ticketAgent('Abel', '0922000001');
    $mine = customerOf($abel, ['name' => 'Mine Player']);
    $stranger = player(0, null, ['name' => 'Stranger Player']);
    fakeWinhouseTickets([winhouseTicket('TK-MINE-1', $mine), winhouseTicket('TK-STRANGER-3', $stranger)]);

    test()->actingAs($abel, 'agent')->get('/agent/tickets/TK-MINE-1/print?auto=1')->assertOk()
        ->assertSee('TK-MINE-1')
        ->assertSee('Saint George')
        ->assertSee('window.print', false)
        ->assertSee('by Abel Agent')
        ->assertSee('href="'.route('agent.tickets').'"', false);

    // Holding the number is enough: the slip prints for a player who is not a customer.
    test()->actingAs($abel, 'agent')->get('/agent/tickets/TK-STRANGER-3/print')->assertOk()
        ->assertSee('TK-STRANGER-3');

    test()->actingAs($abel, 'agent')->get('/agent/tickets/TK-NOPE-999/print')->assertNotFound();
});

it('says plainly when WinHouse is down and falls back to the wallet, still only for customers', function () {
    $abel = ticketAgent('Abel', '0922000001');
    $bekele = ticketAgent('Bekele', '0922000002');
    $mine = customerOf($abel);
    $theirs = customerOf($bekele);
    foreach ([[$mine, 'TK-LOCAL-MINE'], [$theirs, 'TK-LOCAL-THEIRS']] as [$user, $ticket]) {
        WinhouseTransaction::query()->create([
            'provider_transaction_id' => 'tx-'.$ticket, 'action' => 'bet', 'user_id' => $user->id, 'ticket_id' => $ticket,
            'amount' => 40, 'currency' => 'ETB', 'response_balance' => 60, 'payload_hash' => hash('sha256', $ticket),
        ]);
    }
    Http::fake(['*' => Http::response(['error' => 'Internal error'], 500)]);

    test()->actingAs($abel, 'agent')->get('/agent/tickets')->assertOk()
        ->assertSee('WinHouse cannot be reached right now')
        ->assertSee('TK-LOCAL-MINE')
        ->assertDontSee('TK-LOCAL-THEIRS');
});

it('still renders the back-office list and receipt from the shared view', function () {
    $mine = player(0, null, ['name' => 'Mine Player']);
    fakeWinhouseTickets([winhouseTicket('TK-MINE-1', $mine)]);
    $admin = User::factory()->admin()->create(['name' => 'Office Admin']);

    test()->actingAs($admin)->get('/admin/sports/tickets')->assertOk()->assertSee('TK-MINE-1');
    test()->actingAs($admin)->get('/admin/sports/tickets/TK-MINE-1/print')->assertOk()
        ->assertSee('TK-MINE-1')
        ->assertSee('by Office Admin')
        ->assertSee('href="'.route('admin.sports.tickets').'"', false);
});
