<?php

use App\Models\Agent;
use App\Services\FinancialRuleService;
use App\Support\BetPick;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Three owner requests of 29 Oct 2026: agent commission as a percentage set
 * by the administrator, won/lost marks on each pick, and a My Bets menu entry.
 */

beforeEach(function () {
    config([
        'services.winhouse.iframe_key' => 'iframe-key-for-tests',
        'services.winhouse.api_key' => 'api-key-for-tests',
    ]);
    Sleep::fake();
    Http::preventStrayRequests();
});

function commissionAgent(bool $enabled = true, string $nickname = 'Abel'): Agent
{
    return Agent::query()->create([
        'first_name' => $nickname, 'last_name' => 'Agent', 'nickname' => $nickname, 'phone' => '0977'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
        'password' => 'agent-pass-1', 'active' => true, 'balance' => 10000, 'commission_enabled' => $enabled,
    ]);
}

/* ---------------- Commission ---------------- */

it('pays the administrator\'s percentage, separately for deposits and withdrawals', function () {
    test()->setting('agent_commission_mode', 'percent');
    test()->setting('agent_commission_deposit_percent', '1.5');
    test()->setting('agent_commission_withdrawal_percent', '2');
    $rules = app(FinancialRuleService::class);
    $agent = commissionAgent();

    expect($rules->agentCommissionFor($agent, 4000, 'deposit'))->toBe(60.0)
        ->and($rules->agentCommissionFor($agent, 4000, 'withdrawal'))->toBe(80.0)
        // The flat rule's threshold does not apply to a percentage.
        ->and($rules->agentCommissionFor($agent, 100, 'deposit'))->toBe(1.5)
        ->and($rules->agentCommissionFor(commissionAgent(false, 'Bekele'), 4000))->toBe(0.0)
        ->and($rules->agentCommissionSummary())->toBe('1.5% of each deposit and 2% of each withdrawal');
});

it('keeps the flat rule as it was when the mode is not percent', function () {
    $rules = app(FinancialRuleService::class);
    $agent = commissionAgent();

    expect($rules->agentCommissionFor($agent, 9999))->toBe(0.0)
        ->and($rules->agentCommissionFor($agent, 25000, 'withdrawal'))->toBe(200.0);
});

it('lets the administrator choose percent and save both rates', function () {
    $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);

    test()->actingAs($admin)->put(route('admin.settings.update'), [
        'section' => 'agent',
        'agent_commission_amount' => 200, 'agent_commission_threshold' => 10000, 'agent_commission_mode' => 'percent',
        'agent_commission_deposit_percent' => 1, 'agent_commission_withdrawal_percent' => 1.25,
        'agent_settlement_days' => 7, 'min_agent_order' => 100, 'max_agent_order' => 100000,
        'agent_min_float' => 1000, 'agent_low_float_warning' => 2500,
    ])->assertSessionHasNoErrors();

    expect(app(FinancialRuleService::class)->agentCommissionPercent('withdrawal'))->toBe(1.25);

    test()->actingAs($admin)->put(route('admin.settings.update'), [
        'section' => 'agent', 'agent_commission_mode' => 'percent', 'agent_commission_deposit_percent' => 150,
        'agent_commission_amount' => 200, 'agent_commission_threshold' => 10000, 'agent_settlement_days' => 7,
        'min_agent_order' => 100, 'max_agent_order' => 100000, 'agent_min_float' => 1000, 'agent_low_float_warning' => 2500,
    ])->assertSessionHasErrors(['agent_commission_deposit_percent', 'agent_commission_withdrawal_percent']);
});

/* ---------------- Won / lost per pick ---------------- */

function pickRow(string $market, string $pick, string $score, string $status = 'ended', string $home = 'Saint George', string $away = 'Fasil Kenema'): array
{
    return ['market' => $market, 'pick' => $pick, 'score' => $score, 'status' => $status, 'home' => $home, 'away' => $away];
}

it('settles the common full-time markets from the final score', function () {
    expect(BetPick::fromScore(pickRow('1X2', '1', '2:1')))->toBe('won')
        ->and(BetPick::fromScore(pickRow('Full-time result', 'Fasil Kenema', '2:1')))->toBe('lost')
        ->and(BetPick::fromScore(pickRow('Match result', 'Draw', '1-1')))->toBe('won')
        ->and(BetPick::fromScore(pickRow('Double chance', 'X2', '0:0')))->toBe('won')
        ->and(BetPick::fromScore(pickRow('Draw no bet', '1', '1:1')))->toBe('void')
        ->and(BetPick::fromScore(pickRow('Total goals', 'Over 2.5', '2:1')))->toBe('won')
        ->and(BetPick::fromScore(pickRow('Over/Under', 'Under 2.5', '2:1')))->toBe('lost')
        ->and(BetPick::fromScore(pickRow('Both teams to score', 'Yes', '2:0')))->toBe('lost')
        // Not settled by a full-time score, or not finished: unknown, never guessed.
        ->and(BetPick::fromScore(pickRow('1st half result', '1', '2:1')))->toBeNull()
        ->and(BetPick::fromScore(pickRow('Corners over/under', 'Over 9.5', '2:1')))->toBeNull()
        ->and(BetPick::fromScore(pickRow('1X2', '1', '1:0', 'live')))->toBeNull();
});

it('fills what the ticket result proves', function () {
    $lostAcca = ['status' => 'lost', 'type' => 'multiple', 'selections' => [
        pickRow('1X2', '1', '2:0'),
        pickRow('Corners', 'Over 9.5', '2:0'),   // unknown on its own
    ]];
    expect(BetPick::results($lostAcca))->toBe(['won', 'lost']);

    $wonAcca = ['status' => 'won', 'type' => 'multiple', 'selections' => [pickRow('Corners', 'Over 9.5', '1:0'), pickRow('1X2', '1', '1:0')]];
    expect(BetPick::results($wonAcca))->toBe(['won', 'won']);

    $single = ['status' => 'lost', 'type' => 'single', 'selections' => [pickRow('Handicap', '1 (-1)', '1:0')]];
    expect(BetPick::results($single))->toBe(['lost']);
});

/* ---------------- My Bets ---------------- */

it('shows the player their own bets with each pick marked', function () {
    $player = player(0);
    $other = player(0);
    Http::fake(fn (HttpRequest $request) => Http::response(['total' => 2, 'tickets' => [
        [
            'ticket_id' => 'TK-MY-1', 'player_id' => 'player_'.$player->id, 'status' => 'lost', 'type' => 'multiple',
            'placed_at' => '2026-10-28T10:00:00Z', 'stake' => 50, 'total_odds' => 4.2, 'potential_win' => 210, 'payout' => 0,
            'selections' => [
                ['home' => 'Saint George', 'away' => 'Fasil Kenema', 'picks' => [['market' => '1X2', 'pick' => '1', 'odds' => 2.1]], 'match' => ['status' => 'ended', 'score' => '2:0']],
                ['home' => 'Arsenal', 'away' => 'Chelsea', 'picks' => [['market' => '1X2', 'pick' => '2', 'odds' => 2.0]], 'match' => ['status' => 'ended', 'score' => '1:1']],
            ],
        ],
        ['ticket_id' => 'TK-NOT-MINE', 'player_id' => 'player_'.$other->id, 'status' => 'open', 'selections' => []],
    ]]));

    test()->actingAs($player)->get('/player/bets')->assertOk()
        ->assertSee('TK-MY-1')
        ->assertSee('Saint George')
        ->assertSee('aria-label="Won"', false)
        ->assertSee('aria-label="Lost"', false)
        ->assertDontSee('TK-NOT-MINE');

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'player_id=player_'.$player->id));
});

it('asks WinHouse for the chosen tab only, and sends a guest to sign in', function () {
    $player = player(0);
    Http::fake(fn () => Http::response(['total' => 0, 'tickets' => []]));

    test()->actingAs($player)->get('/player/bets?tab=won')->assertOk()->assertSee('No bets here.');
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'status=won'));

    auth()->logout();
    test()->get('/player/bets')->assertRedirect();
});

it('puts My Bets in the menu for a signed-in player', function () {
    Http::fake(fn () => Http::response(['total' => 0, 'tickets' => []]));
    test()->actingAs(player(0))->get('/player/bets')->assertOk()
        ->assertSee('href="'.route('player.bets').'"', false);
});
