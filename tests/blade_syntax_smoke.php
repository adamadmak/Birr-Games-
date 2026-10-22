<?php
/**
 * Blade syntax smoke test: lint the PHP that Blade *emits*.
 *
 *   php tests/blade_syntax_smoke.php
 *
 * `php artisan view:cache` is not this check. It reports success for a template
 * that compiles to broken PHP, because it only writes the compiled file — the
 * parse error surfaces later, when something renders the view, as a 500 on the
 * page rather than an error at deploy time.
 *
 * The failure that prompted this: two directives butted together, `@endif`
 * immediately followed by `@endforeach`. Blade matches directives with a \B
 * assertion, so an `@` preceded by a word character is not read as a directive
 * at all — it was emitted as literal text, leaving an unbalanced `endif` in the
 * compiled PHP. view:cache said "cached successfully"; every render 500'd.
 */
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$compiler = app('blade.compiler');
$failed = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/views'));

foreach ($files as $file) {
    if (! str_ends_with($file->getFilename(), '.blade.php')) continue;
    $php = $compiler->compileString(file_get_contents($file->getPathname()));
    $tmp = tempnam(sys_get_temp_dir(), 'blade').'.php';
    file_put_contents($tmp, $php);
    $out = shell_exec('php -l '.escapeshellarg($tmp).' 2>&1');
    unlink($tmp);
    if (! str_contains($out, 'No syntax errors')) {
        $failed++;
        echo "  BROKEN: ", str_replace($root.'/resources/views/', '', $file->getPathname()), "\n";
        echo "    ", trim(explode("\n", $out)[0] ?? ''), "\n";
    }
}
echo $failed === 0 ? "All Blade templates emit valid PHP.\n" : "$failed template(s) emit broken PHP.\n";
exit($failed === 0 ? 0 : 1);
