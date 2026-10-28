<?php

use App\Models\Agent;

/* The agent's Telegram alerts card shows the real state on the Dashboard and the Profile. */

beforeEach(function () {
    config(['services.telegram.bot_token' => null, 'services.telegram.bot_username' => null]);
    foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_USERNAME'] as $key) {
        putenv($key);
        unset($_SERVER[$key], $_ENV[$key]);
    }
});

function telegramCardAgent(array $extra = []): Agent
{
    return Agent::query()->create(array_merge([
        'first_name' => 'Abel', 'last_name' => 'Agent', 'nickname' => 'Abel', 'phone' => '0966000001',
        'password' => 'agent-pass-1', 'active' => true, 'balance' => 10000,
    ], $extra));
}

it('offers a link code on both pages when Telegram is not linked', function () {
    $agent = telegramCardAgent();

    foreach (['/agent', '/agent/profile'] as $page) {
        test()->actingAs($agent, 'agent')->get($page)->assertOk()
            ->assertSee('Generate Telegram Link Code')
            ->assertDontSee('Not connected');
    }
});

it('shows the waiting code and a button to the bot', function () {
    test()->setting('telegram_bot_username', 'vamoseone_bot');
    $agent = telegramCardAgent(['telegram_link_code' => 'AB12CD34']);

    test()->actingAs($agent, 'agent')->get('/agent/profile')->assertOk()
        ->assertSee('/link AB12CD34')
        ->assertSee('https://t.me/vamoseone_bot', false);
});

it('says linked, with an unlink button, once the bot has linked the chat', function () {
    $agent = telegramCardAgent(['telegram_chat_id' => '555001', 'telegram_linked_at' => now()]);

    test()->actingAs($agent, 'agent')->get('/agent/profile')->assertOk()
        ->assertSee('Your Telegram account is linked')
        ->assertSee('Unlink Telegram');
});

it('generates a code from the profile and comes back to it', function () {
    $agent = telegramCardAgent();

    test()->actingAs($agent, 'agent')->from('/agent/profile')->post('/agent/telegram/generate-code')
        ->assertRedirect('/agent/profile');
    expect($agent->fresh()->telegram_link_code)->toMatch('/^[A-Z0-9]{8}$/');
});
