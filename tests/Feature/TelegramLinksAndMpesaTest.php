<?php

use App\Models\PaymentGateway;
use App\Models\TelegramLoginToken;
use App\Models\TelegramSubscriber;
use App\Services\TelegramPlayerService;
use App\Support\SiteUrl;

/*
 * Item 2: the bot opened the site on whatever TELEGRAM_APP_URL said, which on
 * the host was the old platform subdomain, where WinHouse will not be framed.
 * Item 4: M-PESA as a payment method, as on kulubet.
 */

function tlSubscriber(): TelegramSubscriber
{
    $user = player(0);

    return TelegramSubscriber::query()->create([
        'user_id' => $user->id, 'telegram_user_id' => 7001, 'chat_id' => 7001, 'first_name' => 'Tg', 'phone' => $user->phone, 'active' => true,
    ]);
}

it('builds bot links on the configured public address, not on TELEGRAM_APP_URL', function () {
    putenv('TELEGRAM_APP_URL=https://old-platform-subdomain.example.test');
    $_SERVER['TELEGRAM_APP_URL'] = 'https://old-platform-subdomain.example.test';
    $this->setting(SiteUrl::SETTING, 'https://vamosonebet.com/');

    $link = app(TelegramPlayerService::class)->createLoginLink(tlSubscriber());

    expect($link)->toStartWith('https://vamosonebet.com/telegram/login/');

    putenv('TELEGRAM_APP_URL');
    unset($_SERVER['TELEGRAM_APP_URL']);
});

it('falls back to the address of the current request when no public address is set', function () {
    expect(SiteUrl::clean('https://vamosonebet.com/'))->toBe('https://vamosonebet.com')
        ->and(SiteUrl::clean('javascript:alert(1)'))->toBe('')
        ->and(SiteUrl::clean('https://vamosonebet.com/path'))->toBe('');

    $this->setting(SiteUrl::SETTING, '');
    test()->get('/');   // a request in flight
    expect(SiteUrl::base())->toStartWith('http');
});

it('sends a bot deposit link to the wallet that exists', function () {
    $subscriber = tlSubscriber();
    $link = app(TelegramPlayerService::class)->createLoginLink($subscriber, 'deposit');
    $path = parse_url($link, PHP_URL_PATH);

    test()->get($path)->assertRedirect(route('player.wallet', ['tab' => 'deposit']));
});

it('offers M-PESA once its migration has run, with its own logo', function () {
    $mpesa = PaymentGateway::query()->where('code', 'mpesa')->first();

    expect($mpesa)->not->toBeNull()
        ->and($mpesa->type)->toBe('provider')
        ->and($mpesa->supports_deposit)->toBeTrue()
        ->and($mpesa->supports_withdrawal)->toBeTrue()
        ->and($mpesa->logo_url)->toEndWith('/images/MPSA.png');

    // The order of the logo map: a CBE gateway must not pick up the Ebirr mark.
    expect((new PaymentGateway(['code' => 'cbe-birr', 'name' => 'CBE Birr']))->logo_url)->toEndWith('/images/cbebirr.png')
        ->and((new PaymentGateway(['code' => 'boa', 'name' => 'Bank of Abyssinia']))->logo_url)->toEndWith('/images/BOA.png');
});
