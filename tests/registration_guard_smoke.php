<?php
/**
 * Registration protection smoke test.
 *
 *   php tests/registration_guard_smoke.php
 *
 * Drives POST /register through the HTTP kernel the way a browser (and a bot)
 * would, and checks that:
 *   - a straight POST with no render token is refused and logged;
 *   - a filled honeypot is refused;
 *   - a submission faster than the minimum is refused;
 *   - a genuine submission creates the player, the welcome ledger row and the
 *     allowed event, with the registration IP stored;
 *   - the same phone number cannot register twice;
 *   - the per-network daily quota holds;
 *   - the named rate limiter answers with the form, not a bare 429;
 *   - the hero banner falls back to the bundled artwork and honours a stored path.
 *
 * Runs inside a transaction so the database is left as it was found.
 */

use App\Models\PlatformSetting;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\RegistrationGuard;
use App\Support\HeroBanner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$failures = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? '  PASS ' : '  FAIL ').$label.($detail !== '' && ! $ok ? ' — '.$detail : '')."\n";
    if (! $ok) {
        $failures++;
    }
};

$kernel = $app->make(HttpKernel::class);

/** Send a request through the kernel with a persistent session. */
$send = function (string $method, string $uri, array $data = [], array $cookies = [], string $ip = '203.0.113.10') use ($kernel, $app): \Symfony\Component\HttpFoundation\Response {
    $request = Request::create($uri, $method, $data, $cookies, [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'smoke-test/1.0']);
    // One process plays every browser here, so the guard must not carry the
    // previous request's signed-in user into the next one.
    $app['auth']->forgetGuards();
    // Likewise the session store: Store::loadSession() merges the previous
    // request's attributes into the next session, which would carry a login
    // from one "browser" into another.
    $app['session']->forgetDrivers();
    $app->forgetInstance('session.store');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
};

/** Fetch the register page and pull the token + CSRF out of the HTML, keeping the session cookie. */
$fetchForm = function (string $ip = '203.0.113.10') use ($send): array {
    $page = $send('GET', '/register', [], [], $ip);
    $html = $page->getContent();
    preg_match('/name="_token" value="([^"]+)"/', $html, $csrf);
    preg_match('/name="'.RegistrationGuard::TOKEN_FIELD.'" value="([^"]+)"/', $html, $token);
    $cookies = [];
    foreach ($page->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }

    return ['csrf' => $csrf[1] ?? '', 'token' => $token[1] ?? '', 'cookies' => $cookies, 'status' => $page->getStatusCode(), 'html' => $html];
};

$errorsOf = function (\Symfony\Component\HttpFoundation\Response $response): array {
    if (! $response instanceof \Illuminate\Http\RedirectResponse) {
        return ['<non-redirect status '.$response->getStatusCode().'>'];
    }
    $errors = $response->getSession()?->get('errors');

    return $errors ? $errors->all() : [];
};

/**
 * Named limiters store their hits under md5(limiterName . key), which is what
 * ThrottleRequests computes; clearing the raw key would clear nothing.
 */
$clearLimits = function (string $ip, string $account = ''): void {
    foreach (['register:m:'.$ip, 'register:h:'.$ip] as $key) {
        RateLimiter::clear(md5('register'.$key));
    }
    RateLimiter::clear(md5('login'.'login:ip:'.$ip));
    if ($account !== '') {
        RateLimiter::clear(md5('login'.'login:acct:'.sha1($account)));
        RateLimiter::clear(md5('login'.'login:acct:h:'.sha1($account)));
    }
};

DB::beginTransaction();
try {
    foreach ([
        RegistrationGuard::SETTING_MIN_SECONDS => 2,
        RegistrationGuard::SETTING_MAX_PER_IP_DAILY => 2,
        RegistrationGuard::SETTING_EMAIL_MODE => 'optional',
        \App\Support\Turnstile::SETTING_SITE_KEY => '',
        \App\Support\Turnstile::SETTING_SECRET => '',
    ] as $key => $value) {
        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
    PlatformSetting::flushValueCache();
    foreach (['203.0.113.10', '203.0.113.11', '203.0.113.12'] as $ip) {
        $clearLimits($ip);
    }

    $phone = '2519'.random_int(10000000, 99999999);
    $base = [
        'first_name' => 'Smoke', 'last_name' => 'Tester', 'phone' => $phone,
        'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd',
    ];

    echo "Registration guard\n";

    // 1. Form renders with the protections in place.
    $form = $fetchForm();
    $check('register page renders', $form['status'] === 200);
    $check('render token is in the form', $form['token'] !== '');
    $check('honeypot field is in the form', str_contains($form['html'], 'name="'.RegistrationGuard::HONEYPOT_FIELD.'"'));
    $check('hero artwork markup is on the lobby', str_contains($send('GET', '/')->getContent(), 'class="hero-media"'));

    // 2. No render token: a raw POST that never loaded the form.
    $r = $send('POST', '/register', $base + ['_token' => $form['csrf']], $form['cookies']);
    $check('POST without render token is refused', $r->getStatusCode() === 302 && str_contains(implode(' ', $errorsOf($r)), 'expired'));
    $check('refusal is logged', SecurityEvent::query()->where('reason', 'bad_token')->where('ip', '203.0.113.10')->exists());

    // 3. Honeypot filled.
    $form = $fetchForm();
    $r = $send('POST', '/register', $base + ['_token' => $form['csrf'], RegistrationGuard::TOKEN_FIELD => $form['token'], RegistrationGuard::HONEYPOT_FIELD => 'http://spam.example'], $form['cookies']);
    $check('filled honeypot is refused', $r->getStatusCode() === 302 && $errorsOf($r) !== []);
    $check('honeypot refusal is logged', SecurityEvent::query()->where('reason', 'honeypot')->exists());

    // 4. Too fast: submitted the instant the form was rendered.
    $form = $fetchForm();
    $r = $send('POST', '/register', $base + ['_token' => $form['csrf'], RegistrationGuard::TOKEN_FIELD => $form['token']], $form['cookies']);
    $check('instant submission is refused as too fast', str_contains(implode(' ', $errorsOf($r)), 'quick'));
    $check('too_fast refusal is logged', SecurityEvent::query()->where('reason', 'too_fast')->exists());

    // 5. Genuine: wait out the minimum, then submit the same form.
    sleep(3);
    $r = $send('POST', '/register', $base + ['_token' => $form['csrf'], RegistrationGuard::TOKEN_FIELD => $form['token'], 'email' => 'smoke-'.uniqid().'@example.test'], $form['cookies']);
    $user = User::query()->where('phone', $phone)->first();
    $check('genuine submission creates the player', $user !== null, implode(' | ', $errorsOf($r)));
    $check('registration IP is stored on the player', $user?->registration_ip === '203.0.113.10');
    $check('email is stored lower-cased and unverified', $user !== null && $user->email !== null && $user->email_verified_at === null);
    $check('welcome balance ledger row exists', $user !== null && WalletTransaction::query()->where('user_id', $user->id)->where('type', 'welcome')->exists());
    $check('allowed registration is logged', $user !== null && SecurityEvent::query()->where('outcome', 'allowed')->where('user_id', $user->id)->exists());
    $check('password is hashed', $user !== null && password_verify('Str0ngPassw0rd', $user->password) && $user->password !== 'Str0ngPassw0rd');

    // 6. Server-side validation: weak password, bad username, duplicate phone.
    $form = $fetchForm('203.0.113.11');
    sleep(3);
    $r = $send('POST', '/register', ['first_name' => 'A1', 'last_name' => 'B', 'phone' => '0911000000', 'username' => 'bad name!', 'password' => 'short', 'password_confirmation' => 'short', '_token' => $form['csrf'], RegistrationGuard::TOKEN_FIELD => $form['token']], $form['cookies'], '203.0.113.11');
    $errors = implode(' | ', $errorsOf($r));
    $check('weak password and bad names are rejected server-side', str_contains($errors, 'password') && str_contains($errors, 'letters'), $errors);
    $check('no player was created from the bad submission', ! User::query()->where('phone', '251911000000')->exists());

    $form = $fetchForm('203.0.113.11');
    sleep(3);
    $r = $send('POST', '/register', $base + ['_token' => $form['csrf'], RegistrationGuard::TOKEN_FIELD => $form['token']], $form['cookies'], '203.0.113.11');
    $check('duplicate phone number is refused', str_contains(implode(' ', $errorsOf($r)), 'already uses this phone'));
    $check('duplicate is logged', SecurityEvent::query()->where('reason', 'duplicate_phone')->exists());

    // 7. Per-network quota: the setting is 2 for this run; one success exists on .10.
    $second = $base;
    $second['phone'] = '2519'.random_int(10000000, 99999999);
    $clearLimits('203.0.113.10');
    $form = $fetchForm();
    sleep(3);
    $r = $send('POST', '/register', $second + ['_token' => $form['csrf'], RegistrationGuard::TOKEN_FIELD => $form['token']], $form['cookies']);
    $check('second account from the network is allowed (quota 2)', User::query()->where('phone', $second['phone'])->exists(), implode(' | ', $errorsOf($r)));

    $third = $base;
    $third['phone'] = '2519'.random_int(10000000, 99999999);
    $clearLimits('203.0.113.10');
    $form = $fetchForm();
    sleep(3);
    $r = $send('POST', '/register', $third + ['_token' => $form['csrf'], RegistrationGuard::TOKEN_FIELD => $form['token']], $form['cookies']);
    $check('third account from the network hits the quota', ! User::query()->where('phone', $third['phone'])->exists() && str_contains(implode(' ', $errorsOf($r)), 'network'), implode(' / ', $errorsOf($r)).' created='.var_export(User::query()->where('phone', $third['phone'])->exists(), true).' allowed10='.SecurityEvent::query()->where('ip','203.0.113.10')->where('outcome','allowed')->count());
    $check('quota refusal is logged', SecurityEvent::query()->where('reason', 'ip_quota')->exists());

    // 8. Rate limiter: hammer the endpoint; the sixth call inside a minute is refused with the form.
    $form = $fetchForm('203.0.113.12');
    $clearLimits('203.0.113.12');
    $last = null;
    for ($i = 0; $i < 6; $i++) {
        $last = $send('POST', '/register', ['_token' => $form['csrf']], $form['cookies'], '203.0.113.12');
    }
    $check('sixth attempt in a minute is rate limited with a redirect, not a 429 page', $last->getStatusCode() === 302 && str_contains(implode(' ', $errorsOf($last)), 'Too many'), 'status '.$last->getStatusCode());
    $check('rate limit refusal is logged', SecurityEvent::query()->where('reason', 'rate_limited')->where('ip', '203.0.113.12')->exists());

    // 9. Login: wrong password is logged, right password signs in; per-account limiter engages.
    echo "Sign-in\n";
    $login = $send('GET', '/login');
    preg_match('/name="_token" value="([^"]+)"/', $login->getContent(), $csrf);
    $cookies = [];
    foreach ($login->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    $clearLimits('203.0.113.10', $phone);
    $r = $send('POST', '/login', ['phone' => $phone, 'password' => 'wrong-password', '_token' => $csrf[1]], $cookies);
    $check('wrong password is refused generically', str_contains(implode(' ', $errorsOf($r)), 'do not match'));
    $check('failed sign-in is logged against the account', SecurityEvent::query()->where('type', 'login')->where('outcome', 'failed')->where('identifier', $phone)->exists());
    $r = $send('POST', '/login', ['phone' => $phone, 'password' => 'Str0ngPassw0rd', '_token' => $csrf[1]], $cookies);
    $check('correct password signs in', $r->getStatusCode() === 302 && rtrim((string) $r->headers->get('Location'), '/') === rtrim(url('/'), '/'), $r->getStatusCode().' '.$r->headers->get('Location').' '.implode(' / ', $errorsOf($r)));

    for ($i = 0; $i < 6; $i++) {
        // A fresh guest session per guess: the previous one is now signed in.
        $fresh = $send('GET', '/login', [], [], '198.51.100.'.($i + 1));
        preg_match('/name="_token" value="([^"]+)"/', $fresh->getContent(), $freshCsrf);
        $freshCookies = [];
        foreach ($fresh->headers->getCookies() as $cookie) { $freshCookies[$cookie->getName()] = $cookie->getValue(); }
        $r = $send('POST', '/login', ['phone' => $phone, 'password' => 'wrong-'.$i, '_token' => $freshCsrf[1]], $freshCookies, '198.51.100.'.($i + 1));
    }
    $check('per-account limiter stalls guessing spread across addresses', str_contains(implode(' ', $errorsOf($r)), 'Too many sign-in'));

    // 10. Hero banner resolution.
    echo "Hero banner\n";
    PlatformSetting::query()->where('key', HeroBanner::SETTING_DESKTOP)->delete();
    PlatformSetting::query()->where('key', HeroBanner::SETTING_MOBILE)->delete();
    PlatformSetting::flushValueCache();
    $check('desktop falls back to the bundled artwork', str_ends_with(HeroBanner::desktopUrl(), HeroBanner::DEFAULT_DESKTOP));
    $check('mobile falls back to the bundled mobile artwork', str_ends_with(HeroBanner::mobileUrl(), HeroBanner::DEFAULT_MOBILE));
    $check('a saved path whose image is gone falls back to the bundled artwork', (function () {
        PlatformSetting::query()->updateOrCreate(['key' => HeroBanner::SETTING_DESKTOP], ['value' => 'hero/desktop-test.webp']);
        PlatformSetting::flushValueCache();

        return str_ends_with(HeroBanner::desktopUrl(), HeroBanner::DEFAULT_DESKTOP) && HeroBanner::isMissing('desktop');
    })());
    $check('bundled artwork files exist', is_file(public_path(HeroBanner::DEFAULT_DESKTOP)) && is_file(public_path(HeroBanner::DEFAULT_MOBILE)));
} finally {
    DB::rollBack();
    PlatformSetting::flushValueCache();
    foreach (['203.0.113.10', '203.0.113.11', '203.0.113.12'] as $ip) {
        $clearLimits($ip, $phone ?? '');
    }
    for ($i = 1; $i <= 6; $i++) {
        $clearLimits('198.51.100.'.$i);
    }
}

echo $failures === 0 ? "All registration guard checks passed.\n" : "$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
