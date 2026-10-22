<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->defaultServeWorkers();
        $this->boundDatabaseWaits();
    }

    /**
     * Never let a dead database socket hold a worker.
     *
     * mysqlnd's read timeout defaults to 86400 seconds — a worker whose MySQL
     * connection dies mid-read (the database lives on a shared cluster node;
     * its network hiccups are not ours to prevent) will sit in that read for up
     * to a day. With a handful of prefork workers, a few poisoned connections
     * froze the whole site until someone restarted the container: measured in
     * production as total outages recurring roughly hourly, recovering only on
     * restart. Thirty seconds turns that failure into one slow request.
     *
     * ini_set because this must hold for every entry point — web workers,
     * artisan, the queue — and the container's php.ini is not ours to edit.
     */
    private function boundDatabaseWaits(): void
    {
        @ini_set('mysqlnd.net_read_timeout', '30');
    }

    /**
     * Give `artisan serve` more than one lane, on hosts that run it as the
     * production server.
     *
     * This app is deployed as a container whose entrypoint is plain
     * `php artisan serve` — one PHP worker, one request at a time. Any single
     * slow request (a provider API call, the catalog sync) therefore queued the
     * entire site behind it; that was measured taking production down.
     *
     * Two variables are needed, not one. ServeCommand only honours
     * PHP_CLI_SERVER_WORKERS when --no-reload is passed or it believes it is
     * under Sail — otherwise it silently downgrades to a single server with a
     * warning nobody reads in a container log. The entrypoint is not ours to
     * edit, so the Sail flag is the lever left. Its only runtime effect in this
     * framework version is that downgrade check, and nothing in this codebase
     * reads it.
     *
     * Both are defaults, not overrides: a real value from the environment wins,
     * and nothing here runs for web requests, queues, or any other command.
     * Four workers fits the container's 512Mi memory limit — measured at about
     * 75MB peak per worker on the heaviest page; do not raise it without
     * raising the limit.
     */
    private function defaultServeWorkers(): void
    {
        if (! $this->app->runningInConsole() || (($_SERVER['argv'][1] ?? null) !== 'serve')) {
            return;
        }

        $_ENV['PHP_CLI_SERVER_WORKERS'] = $_ENV['PHP_CLI_SERVER_WORKERS'] ?? '4';
        $_ENV['LARAVEL_SAIL'] = $_ENV['LARAVEL_SAIL'] ?? '1';
    }

    public function boot(): void
    {
        $this->persistSessionsAcrossDeploys();

        if (request()->secure() || request()->header('x-forwarded-proto') === 'https') {
            URL::forceScheme('https');
        }

        // throttle:login / throttle:register / throttle:verification on the auth routes.
        \App\Support\AuthRateLimits::register();

        Paginator::defaultView('vendor.pagination.tailwind');
        Paginator::defaultSimpleView('vendor.pagination.simple-tailwind');

        $this->reportLazyLoading();

        // A catalog edit orphans the lobby's shared snapshot at once, so the cache
        // never makes an admin wait a minute to see their own change. Only a save
        // that actually changed something counts: Eloquent fires `saved` on clean
        // saves too, and the hourly feed sync re-saves every game — without the
        // wasChanged() guard each sync fired thousands of bumps and held the cache
        // permanently cold through its own busiest window. The sync's mass-update
        // phases bypass model events entirely, so the synchronizer also bumps once
        // itself when it finishes.
        foreach ([\App\Models\Game::class, \App\Models\GameProvider::class, \App\Models\GameCategory::class] as $model) {
            $model::saved(fn ($saved) => $saved->wasChanged() ? \App\Support\CatalogCache::bump() : null);
            $model::deleted(fn () => \App\Support\CatalogCache::bump());
        }
    }

    /**
     * Keep people signed in across a deploy.
     *
     * config/session.php falls back to the `file` driver unless SESSION_DRIVER
     * is set, or APP_ENV says production — and neither reaches this app. The
     * host builds the config cache at BUILD time with no runtime environment,
     * so every env() in it resolves empty and the fallback wins.
     *
     * File sessions live in the container's own filesystem, and a deploy
     * replaces the container. So every deploy signed out every administrator,
     * agent and player, and any page still open posted a token whose session no
     * longer existed — a bare 419 on a button that had worked a minute earlier.
     *
     * The `sessions` table outlives the container, so it is used when it is
     * there. Guarded rather than simply made the default: migrations do not run
     * on every brand's deploy (the two buildCommands differ), and a missing
     * table would take out signing in altogether — a worse failure than the one
     * being fixed. One catalogue lookup, and only while the driver is still
     * the file fallback.
     */
    private function persistSessionsAcrossDeploys(): void
    {
        if (config('session.driver') !== 'file') {
            return;
        }

        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('sessions')) {
                config(['session.driver' => 'database']);
            }
        } catch (\Throwable) {
            // Nothing to ask. Leave the file driver rather than trading a lost
            // session for a site that cannot start one at all.
        }
    }

    /**
     * Name the N+1s instead of letting them hide.
     *
     * A relation touched inside a loop issues one query per row, which is
     * invisible on a developer's twenty records and ruinous on an operator's
     * twenty thousand — and nothing in the panel currently says it is happening.
     *
     * This reports rather than throws. Laravel's strict mode raises an exception
     * on the first violation, which would take a page that works today and break
     * it in front of whoever opened it; the point here is to find the offenders,
     * not to make finding them an outage. Production is left alone entirely: the
     * check costs nothing there because it is never armed.
     */
    private function reportLazyLoading(): void
    {
        if ($this->app->isProduction()) {
            return;
        }

        Model::preventLazyLoading();

        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            Log::warning('N+1: '.$model::class.'::$'.$relation.' was lazy loaded.', [
                'model' => $model::class,
                'relation' => $relation,
                'url' => request()?->fullUrl(),
            ]);
        });
    }
}

