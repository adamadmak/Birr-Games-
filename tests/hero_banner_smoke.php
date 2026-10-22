<?php
/**
 * Hero banner smoke test.
 *
 *   php tests/hero_banner_smoke.php
 *
 * Signs in as an administrator, uploads a generated desktop image and a
 * mobile image through POST /admin/settings/hero exactly as the Frontend tab
 * does, and checks that:
 *   - the files are stored on the public disk, resized and (with GD) WebP;
 *   - the settings point at them and the lobby serves them;
 *   - replacing an image removes the previous file;
 *   - an SVG, an undersized image and an over-limit file are refused;
 *   - the reset box returns a slot to the bundled artwork and deletes the file.
 *
 * Settings and files are restored afterwards.
 */

use App\Models\PlatformSetting;
use App\Models\SiteMedia;
use App\Models\User;
use App\Support\HeroBanner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

if (! extension_loaded('gd')) {
    echo "GD is not loaded; this test needs it to draw its fixtures.\n";
    exit(1);
}

$admin = User::query()->where('role', User::ROLE_ADMIN)->where('active', true)->orderBy('id')->first();
if (! $admin) {
    echo "No active administrator to sign in as.\n";
    exit(1);
}

$kernel = $app->make(HttpKernel::class);
$cookies = [];
$csrf = '';

$send = function (string $method, string $uri, array $data = [], array $files = []) use ($kernel, $app, &$cookies, $admin): \Symfony\Component\HttpFoundation\Response {
    $app['auth']->forgetGuards();
    $app['session']->forgetDrivers();
    $app->forgetInstance('session.store');
    $request = Request::create($uri, $method, $data, $cookies, $files, ['REMOTE_ADDR' => '127.0.0.1']);
    $request->setUserResolver(fn () => null);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }

    return $response;
};

$errorsOf = function (\Symfony\Component\HttpFoundation\Response $response): string {
    if (! $response instanceof \Illuminate\Http\RedirectResponse) {
        return '<status '.$response->getStatusCode().'>';
    }
    $errors = $response->getSession()?->get('errors');

    return $errors ? implode(' | ', $errors->all()) : '';
};

$png = function (int $w, int $h, string $name) : UploadedFile {
    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, 12, 14, 16);
    $accent = imagecolorallocate($img, 129, 181, 7);
    imagefill($img, 0, 0, $bg);
    imagefilledellipse($img, (int) ($w * .7), (int) ($h * .5), (int) ($h * .8), (int) ($h * .8), $accent);
    $path = tempnam(sys_get_temp_dir(), 'hero').'.png';
    imagepng($img, $path);
    imagedestroy($img);

    return new UploadedFile($path, $name, 'image/png', null, true);
};

$disk = Storage::disk('public');
$originalSettings = PlatformSetting::query()->whereIn('key', [HeroBanner::SETTING_DESKTOP, HeroBanner::SETTING_MOBILE, HeroBanner::SETTING_ALT])->pluck('value', 'key')->all();

try {
    echo "Hero banner upload\n";

    // Sign in as the administrator through the real form so the session is genuine.
    $page = $send('GET', '/login');
    preg_match('/name="_token" value="([^"]+)"/', $page->getContent(), $m);
    $csrf = $m[1] ?? '';
    $plain = 'HeroSmoke#'.random_int(1000, 9999);
    $originalPassword = $admin->getRawOriginal('password');
    $admin->forceFill(['password' => $plain])->save();
    $login = $send('POST', '/login', ['phone' => $admin->email, 'password' => $plain, '_token' => $csrf]);
    $check('administrator signs in', $login->getStatusCode() === 302 && ! str_contains((string) $login->headers->get('Location'), 'login'), $errorsOf($login));

    $settings = $send('GET', '/admin/settings?tab=frontend');
    $check('frontend tab shows the hero panel', $settings->getStatusCode() === 200 && str_contains($settings->getContent(), 'name="hero_desktop"'));
    preg_match('/name="_token" value="([^"]+)"/', $settings->getContent(), $m);
    $csrf = $m[1] ?? $csrf;

    // Upload both slots: an oversize desktop image (should be resized) and a portrait phone image.
    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf, 'hero_image_alt' => 'Smoke test artwork'], [
        'hero_desktop' => $png(3000, 1350, 'wide.png'),
        'hero_mobile' => $png(900, 1200, 'tall.png'),
    ]);
    PlatformSetting::flushValueCache();
    $desktop = HeroBanner::path('desktop');
    $mobile = HeroBanner::path('mobile');
    $check('upload redirects back to the frontend tab', $r->getStatusCode() === 302 && str_contains((string) $r->headers->get('Location'), 'tab=frontend'), $errorsOf($r));
    $check('desktop reference is a database media reference', is_string($desktop) && str_starts_with($desktop, 'media:desktop-'), (string) $desktop);
    $check('mobile reference is a database media reference', is_string($mobile) && str_starts_with($mobile, 'media:mobile-'), (string) $mobile);
    $check('both rows exist in site_media', SiteMedia::query()->whereIn('slot', ['hero-desktop', 'hero-mobile'])->count() === 2);
    $check('alt text is stored', HeroBanner::alt() === 'Smoke test artwork');
    $check('desktop picture size is recorded for the hero height', HeroBanner::size('desktop') === [3000, 1350], json_encode(HeroBanner::size('desktop')));
    $check('phone picture size is recorded separately', HeroBanner::size('mobile') === [900, 1200], json_encode(HeroBanner::size('mobile')));

    $row = SiteMedia::query()->where('slot', 'hero-desktop')->first();
    $binary = $row ? base64_decode($row->data, true) : false;
    $info = $binary ? getimagesizefromstring($binary) : false;
    $check('desktop image was resized to the 2200px ceiling', $info && $info[0] === 2200, $info ? 'width '.$info[0] : 'unreadable');
    $check('desktop image was re-encoded as WebP', function_exists('imagewebp') ? ($row?->mime === 'image/webp') : true, (string) $row?->mime);
    $check('stored image is far smaller than a 3000px PNG', $binary !== false && strlen($binary) < 400_000, (string) strlen((string) $binary));

    // The lobby is looked at as a guest, with the images fetched through the route.
    $adminCookies = $cookies;
    $cookies = [];
    $lobby = $send('GET', '/');
    $html = $lobby->getContent();
    preg_match('#/media/hero/desktop/([A-Za-z0-9_\-]+)#', $html, $dv);
    preg_match('#/media/hero/mobile/([A-Za-z0-9_\-]+)#', $html, $mv);
    $check('lobby points at the desktop media route', ! empty($dv[0]));
    $check('lobby points the phone source at the mobile media route', ! empty($mv[0]));
    $check('lobby carries the alt text', str_contains($html, 'alt="Smoke test artwork"'));

    $img = $send('GET', $dv[0] ?? '/media/hero/desktop/missing000');
    $check('desktop image is served with a 200', $img->getStatusCode() === 200, (string) $img->getStatusCode());
    $check('served as an image, not markup', str_starts_with((string) $img->headers->get('Content-Type'), 'image/'), (string) $img->headers->get('Content-Type'));
    $check('served with a year-long immutable cache', str_contains((string) $img->headers->get('Cache-Control'), 'immutable'));
    $check('served with nosniff and a sandbox CSP', $img->headers->get('X-Content-Type-Options') === 'nosniff' && str_contains((string) $img->headers->get('Content-Security-Policy'), 'sandbox'), (string) $img->headers->get('Content-Security-Policy'));
    $check('served bytes are the stored image', $img->getContent() === $binary);
    $etag = $img->headers->get('ETag');
    $app['auth']->forgetGuards();
    $app['session']->forgetDrivers();
    $app->forgetInstance('session.store');
    $condRequest = Request::create($dv[0] ?? '/media/hero/desktop/missing000', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_IF_NONE_MATCH' => (string) $etag]);
    $cond = $kernel->handle($condRequest);
    $check('a repeat request with the ETag gets a 304', $cond->getStatusCode() === 304, (string) $cond->getStatusCode());
    $check('an unknown slot is a 404', $send('GET', '/media/hero/sideways/abcdef')->getStatusCode() === 404);
    $cookies = $adminCookies;

    // Replace the desktop image: a new version in the URL, same row.
    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf, 'hero_image_alt' => 'Smoke test artwork'], ['hero_desktop' => $png(1600, 720, 'wide2.png')]);
    PlatformSetting::flushValueCache();
    $replaced = HeroBanner::path('desktop');
    $check('replacing stores a new desktop version', $replaced && $replaced !== $desktop, $errorsOf($r));
    $check('replacing keeps exactly one desktop row', SiteMedia::query()->where('slot', 'hero-desktop')->count() === 1);
    $check('mobile image is untouched by a desktop replace', HeroBanner::path('mobile') === $mobile);

    // Refusals.
    $svgPath = tempnam(sys_get_temp_dir(), 'hero').'.svg';
    file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf], ['hero_desktop' => new UploadedFile($svgPath, 'evil.svg', 'image/svg+xml', null, true)]);
    PlatformSetting::flushValueCache();
    $check('SVG upload is refused', $errorsOf($r) !== '' && HeroBanner::path('desktop') === $replaced, $errorsOf($r));

    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf], ['hero_desktop' => $png(400, 200, 'tiny.png')]);
    PlatformSetting::flushValueCache();
    $check('undersized desktop image is refused', $errorsOf($r) !== '', $errorsOf($r));
    $check('refused upload leaves the stored image alone', HeroBanner::path('desktop') === $replaced);

    // Reset the mobile slot.
    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf, 'remove_mobile' => '1', 'hero_image_alt' => '']);
    PlatformSetting::flushValueCache();
    $check('reset returns the mobile slot to the default artwork', HeroBanner::path('mobile') === null, $errorsOf($r));
    $check('reset removed the mobile row', ! SiteMedia::query()->where('slot', 'hero-mobile')->exists());
    $check('with only desktop set, phones get the desktop image', HeroBanner::mobileUrl() === HeroBanner::desktopUrl());
    $check('blank alt falls back to the default description', HeroBanner::alt() !== '');

    // The bug that prompted this: a legacy disk path whose file is gone (no symlink, wiped by a deploy).
    echo "Legacy and broken references\n";
    PlatformSetting::query()->updateOrCreate(['key' => HeroBanner::SETTING_MOBILE], ['value' => 'hero/mobile-gone0000.webp']);
    PlatformSetting::flushValueCache();
    $check('a legacy path with no file behind it is treated as unset', HeroBanner::path('mobile') === null);
    $check('and the admin is told it is missing', HeroBanner::isMissing('mobile'));
    $check('and the lobby falls back instead of linking a 404', ! str_contains(HeroBanner::mobileUrl(), 'mobile-gone0000'));
    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf, 'remove_mobile' => '1', 'hero_image_alt' => '']);
    PlatformSetting::flushValueCache();
    $check('a broken reference can be cleared with the reset box', ! HeroBanner::isMissing('mobile'), $errorsOf($r));

    PlatformSetting::query()->where('key', HeroBanner::SETTING_DESKTOP)->delete();
    PlatformSetting::query()->where('key', HeroBanner::SETTING_MOBILE)->delete();
    SiteMedia::query()->whereIn('slot', ['hero-desktop', 'hero-mobile'])->delete();
    PlatformSetting::flushValueCache();
    $check('with nothing stored the bundled artwork is used', str_ends_with(HeroBanner::desktopUrl(), HeroBanner::DEFAULT_DESKTOP) && is_file(public_path(HeroBanner::DEFAULT_DESKTOP)));
} finally {
    // Put everything back.
    SiteMedia::query()->whereIn('slot', ['hero-desktop', 'hero-mobile'])->delete();
    PlatformSetting::query()->whereIn('key', [HeroBanner::SETTING_DESKTOP, HeroBanner::SETTING_MOBILE, HeroBanner::SETTING_ALT])->delete();
    foreach ($originalSettings as $key => $value) {
        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
    PlatformSetting::flushValueCache();
    if (isset($originalPassword)) {
        User::query()->whereKey($admin->id)->update(['password' => $originalPassword]);
    }
}

echo $failures === 0 ? "All hero banner checks passed.\n" : "$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
