<?php

use App\Models\Agent;
use App\Services\WinhouseTicketsClient;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Booking codes (WinHouse docs/agent.md 4.8). A customer saves a slip in the
 * book and brings the code; the agent finds it on the Counter page and opens
 * it in the book as the counter, where the stake and picks can still change.
 */

beforeEach(function () {
    config([
        'services.winhouse.iframe_key' => 'iframe-key-for-tests',
        'services.winhouse.api_key' => 'api-key-for-tests',
    ]);
    Sleep::fake();
    Http::preventStrayRequests();
});

function bookingAgent(): Agent
{
    return Agent::query()->create([
        'first_name' => 'Abel', 'last_name' => 'Agent', 'nickname' => 'Abel', 'phone' => '0944000001',
        'password' => 'agent-pass-1', 'active' => true, 'balance' => 10000,
    ]);
}

function fakeBooking(int $status, array $booking = []): void
{
    Http::fake(fn (HttpRequest $request) => str_contains($request->url(), '/tenant/api/bookings/')
        ? Http::response($booking === [] ? ['ok' => false, 'error' => 'not found'] : ['ok' => $status === 200, 'booking' => $booking], $status)
        : Http::response([], 404));
}

function sampleBooking(array $extra = []): array
{
    return array_merge([
        'code' => 'K7M4QZT', 'state' => 'active', 'created_at' => '2026-10-28T08:00:00Z', 'updated_at' => '2026-10-28T08:00:00Z',
        'expires_at' => '2026-10-29T15:00:00Z', 'stake' => 100, 'mode' => 'multiple', 'system_size' => null,
        'available_count' => 1, 'odds_changed' => true,
        'selections' => [
            ['event_id' => 1, 'event_name' => 'Saint George v Fasil Kenema', 'home' => 'Saint George', 'away' => 'Fasil Kenema', 'league' => 'Premier League', 'country' => 'Ethiopia',
             'kickoff' => '2026-10-29T15:00:00Z', 'market' => '1X2', 'outcome' => '1', 'odds_at_booking' => 2.10, 'current_odds' => 2.25, 'available' => true, 'unavailable_reason' => null],
            ['event_id' => 2, 'event_name' => 'Arsenal v Chelsea', 'home' => 'Arsenal', 'away' => 'Chelsea', 'league' => 'Premier League', 'country' => 'England',
             'kickoff' => '2026-10-28T09:00:00Z', 'market' => '1X2', 'outcome' => '2', 'odds_at_booking' => 3.40, 'current_odds' => null, 'available' => false, 'unavailable_reason' => 'started'],
        ],
    ], $extra);
}

it('accepts a code in any case and with spaces, and refuses anything else without calling WinHouse', function () {
    expect(WinhouseTicketsClient::bookingCode(' k7m4 qzt '))->toBe('K7M4QZT')
        ->and(WinhouseTicketsClient::bookingCode('K7M4QZ'))->toBeNull()
        ->and(WinhouseTicketsClient::bookingCode('K7M4QZ0'))->toBeNull()   // 0 is never used
        ->and(WinhouseTicketsClient::bookingCode('../tickets'))->toBeNull()
        ->and(WinhouseTicketsClient::bookingCode(['K7M4QZT']))->toBeNull();

    test()->actingAs(bookingAgent(), 'agent')->get('/agent/counter?code=nope')->assertOk()
        ->assertSee('A booking code is 7 letters and digits');
    Http::assertNothingSent();
});

it('shows the booked slip at current odds, with dropped picks marked, and offers to open it', function () {
    fakeBooking(200, sampleBooking());

    test()->actingAs(bookingAgent(), 'agent')->get('/agent/counter?code=k7m4qzt')->assertOk()
        ->assertSee('Saint George v Fasil Kenema')
        ->assertSee('2.25')
        ->assertSee('Dropped: started')
        ->assertSee('1 of 2')
        ->assertSee('Open this slip as the counter');

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/tenant/api/bookings/K7M4QZT')
        && $request->hasHeader('Authorization', 'Bearer api-key-for-tests'));
});

it('says when a code has expired, and offers nothing to open', function () {
    fakeBooking(410, sampleBooking(['state' => 'expired', 'available_count' => 0]));
    test()->actingAs(bookingAgent(), 'agent')->get('/agent/counter?code=K7M4QZT')->assertOk()
        ->assertSee('This code has expired')
        ->assertDontSee('Open this slip as the counter');
});

it('says when a code does not exist', function () {
    fakeBooking(404);
    test()->actingAs(bookingAgent(), 'agent')->get('/agent/counter?code=K7M4QZT')->assertOk()
        ->assertSee('No booking with that code');
});

it('opens the book as the counter with the code loaded', function () {
    $agent = bookingAgent();

    test()->actingAs($agent, 'agent')->post('/agent/counter/open', ['booking' => 'k7m4qzt'])
        ->assertRedirect('/?booking=K7M4QZT#sportsbook');

    // A bad code is ignored rather than passed on.
    test()->actingAs($agent, 'agent')->post('/agent/counter/open', ['booking' => '"><script>'])
        ->assertRedirect('/');
});

it('passes a booking code on our address through to the book', function () {
    test()->get('/?booking=k7m4qzt')->assertOk()->assertSee('&amp;booking=K7M4QZT', false);
    test()->get('/?booking=<x>')->assertOk()->assertDontSee('booking=&lt;', false);
});
