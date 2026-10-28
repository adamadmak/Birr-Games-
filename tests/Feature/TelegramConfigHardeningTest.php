<?php

use App\Services\TelegramBotService;

/*
 * Values for the bot are read from the admin, from config and, as a last
 * resort, from the process environment. Whatever the source, a value that
 * does not have the right shape must be ignored: a token where a username
 * belongs, a placeholder where a token belongs, a short word where a webhook
 * secret belongs. These are the shapes found in the host's settings on
 * 28 Oct 2026.
 */

const TG_TOKEN = '123456789:AAHtestTokenForPestOnly_abcdefghij';
const TG_OTHER_TOKEN = '987654321:AAHanotherTokenForPest_abcdefghij';

function setProcessEnv(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_SERVER[$key], $_ENV[$key]);

        return;
    }
    putenv($key.'='.$value);
    $_SERVER[$key] = $value;
    $_ENV[$key] = $value;
}

function storeBot(string $token, ?string $username): void
{
    test()->setting('telegram_bot_token_encrypted', \Illuminate\Support\Facades\Crypt::encryptString($token));
    if ($username !== null) {
        test()->setting('telegram_bot_username', $username);
    }
}

afterEach(function () {
    foreach (['TELEGRAM_BOT_USERNAME', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET'] as $key) {
        setProcessEnv($key, null);
    }
});

beforeEach(function () {
    config(['services.telegram.bot_token' => null, 'services.telegram.bot_username' => null, 'services.telegram.webhook_secret' => null]);
    // Laravel loads the developer's .env during tests; start from nothing.
    foreach (['TELEGRAM_BOT_USERNAME', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET'] as $key) {
        setProcessEnv($key, null);
    }
});

it('never offers a bot token as the bot username', function () {
    setProcessEnv('TELEGRAM_BOT_USERNAME', TG_TOKEN);

    expect(app(TelegramBotService::class)->botUsername())->toBe('');

    storeBot(TG_TOKEN, 'vamoseone_bot');
    expect(app(TelegramBotService::class)->botUsername())->toBe('vamoseone_bot');
});

it('has no default username, and strips the @', function () {
    expect(app(TelegramBotService::class)->botUsername())->toBe('');

    config(['services.telegram.bot_username' => '@vamoseone_bot']);
    expect(app(TelegramBotService::class)->botUsername())->toBe('vamoseone_bot');

    config(['services.telegram.bot_username' => 'bad name with spaces']);
    expect(app(TelegramBotService::class)->botUsername())->toBe('');
});

it('ignores a placeholder token and prefers the token saved in the admin over the environment', function () {
    $bot = app(TelegramBotService::class);

    setProcessEnv('TELEGRAM_BOT_TOKEN', '3000');
    expect($bot->configured())->toBeFalse()
        ->and($bot->signingToken())->toBe('')
        ->and($bot->configurationSources()['process'])->toBeFalse();

    setProcessEnv('TELEGRAM_BOT_TOKEN', TG_OTHER_TOKEN);
    storeBot(TG_TOKEN, null);
    expect($bot->signingToken())->toBe(TG_TOKEN);
});

it('accepts only a webhook secret of the shape Telegram allows', function () {
    storeBot(TG_TOKEN, 'vamoseone_bot');
    $bot = app(TelegramBotService::class);
    $derived = hash('sha256', 'lucy-telegram-webhook|'.TG_TOKEN);

    // A short word in the environment is not a secret: refused, and not registered either.
    setProcessEnv('TELEGRAM_WEBHOOK_SECRET', 'birdgame_bot');
    expect($bot->validWebhookSecret('birdgame_bot'))->toBeFalse()
        ->and($bot->webhookSecret())->toBe($derived)
        ->and($bot->validWebhookSecret($derived))->toBeTrue();

    // A proper one is honoured.
    setProcessEnv('TELEGRAM_WEBHOOK_SECRET', 'a-proper-secret-of-32-characters');
    expect($bot->webhookSecret())->toBe('a-proper-secret-of-32-characters')
        ->and($bot->validWebhookSecret('a-proper-secret-of-32-characters'))->toBeTrue()
        ->and($bot->validWebhookSecret(''))->toBeFalse();
});

it('refuses a webhook call carrying the short word as its secret', function () {
    storeBot(TG_TOKEN, 'vamoseone_bot');
    setProcessEnv('TELEGRAM_WEBHOOK_SECRET', 'birdgame_bot');

    test()->postJson('/api/integrations/telegram/webhook', ['update_id' => 777], ['X-Telegram-Bot-Api-Secret-Token' => 'birdgame_bot'])
        ->assertStatus(403);

    expect(\App\Models\TelegramUpdate::query()->count())->toBe(0);
});
