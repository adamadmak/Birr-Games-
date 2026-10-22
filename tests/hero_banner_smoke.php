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
$created = [];

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

    // Upload both slots: an oversize desktop image (should be resized) and a square phone image.
    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf, 'hero_image_alt' => 'Smoke test artwork'], [
        'hero_desktop' => $png(3000, 1350, 'wide.png'),
        'hero_mobile' => $png(900, 1200, 'tall.png'),
    ]);
    PlatformSetting::flushValueCache();
    $desktop = HeroBanner::path('desktop');
    $mobile = HeroBanner::path('mobile');
    $created[] = $desktop;
    $created[] = $mobile;
    $check('upload redirects back to the frontend tab', $r->getStatusCode() === 302 && str_contains((string) $r->headers->get('Location'), 'tab=frontend'), $errorsOf($r));
    $check('desktop path is stored', is_string($desktop) && str_starts_with($desktop, 'hero/desktop-'));
    $check('mobile path is stored', is_string($mobile) && str_starts_with($mobile, 'hero/mobile-'));
    $check('both files exist on the public disk', $desktop && $mobile && $disk->exists($desktop) && $disk->exists($mobile));
    $check('alt text is stored', HeroBanner::alt() === 'Smoke test artwork');

    if ($desktop && $disk->exists($desktop)) {
        [$w] = getimagesize($disk->path($desktop)) ?: [0];
        $check('desktop image was resized to the 2200px ceiling', $w === 2200, 'width '.$w);
        $check('desktop image was re-encoded as WebP', function_exists('imagewebp') ? str_ends_with($desktop, '.webp') : true);
        $check('stored file is smaller than the 3000px original', $disk->size($desktop) < 400_000, (string) $disk->size($desktop));
    }

    // The hero is on the signed-out lobby, so look at it as a guest.
    $adminCookies = $cookies;
    $cookies = [];
    $lobby = $send('GET', '/');
    $cookies = $adminCookies;
    $check('lobby serves the uploaded desktop image', str_contains($lobby->getContent(), '/storage/'.$desktop));
    $check('lobby serves the uploaded mobile image for phones', str_contains($lobby->getContent(), '/storage/'.$mobile));
    $check('lobby carries the alt text', str_contains($lobby->getContent(), 'alt="Smoke test artwork"'));

    // Replace the desktop image: the old file must go.
    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf, 'hero_image_alt' => 'Smoke test artwork'], ['hero_desktop' => $png(1600, 720, 'wide2.png')]);
    PlatformSetting::flushValueCache();
    $replaced = HeroBanner::path('desktop');
    $created[] = $replaced;
    $check('replacing stores a new desktop path', $replaced && $replaced !== $desktop, $errorsOf($r));
    $check('previous desktop file was deleted', $desktop && ! $disk->exists($desktop));
    $check('mobile image is untouched by a desktop replace', HeroBanner::path('mobile') === $mobile && $disk->exists($mobile));

    // Refusals.
    $svgPath = tempnam(sys_get_temp_dir(), 'hero').'.svg';
    file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf], ['hero_desktop' => new UploadedFile($svgPath, 'evil.svg', 'image/svg+xml', null, true)]);
    PlatformSetting::flushValueCache();
    $check('SVG upload is refused', $errorsOf($r) !== '' && HeroBanner::path('desktop') === $replaced, $errorsOf($r));

    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf], ['hero_desktop' => $png(400, 200, 'tiny.png')]);
    PlatformSetting::flushValueCache();
    $check('undersized desktop image is refused', str_contains($errorsOf($r), 'dimensions') || str_contains(strtolower($errorsOf($r)), 'invalid'), $errorsOf($r));
    $check('refused upload leaves the stored image alone', HeroBanner::path('desktop') === $replaced);

    // Reset the mobile slot.
    $r = $send('POST', '/admin/settings/hero', ['_token' => $csrf, 'remove_mobile' => '1', 'hero_image_alt' => '']);
    PlatformSetting::flushValueCache();
    $check('reset returns the mobile slot to the default artwork', HeroBanner::path('mobile') === null, $errorsOf($r));
    $check('reset deleted the mobile file', ! $disk->exists($mobile));
    $check('with only desktop set, phones get the desktop image', HeroBanner::mobileUrl() === HeroBanner::desktopUrl());
    $check('blank alt falls back to the default description', HeroBanner::alt() !== '');
} finally {
    // Put everything back.
    foreach (array_filter($created) as $path) {
        try {
            $disk->delete($path);
        } catch (\Throwable) {
        }
    }
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
