<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append([
            \App\Http\Middleware\EnforceProductionTransport::class,
            \App\Http\Middleware\SecurityHeaders::class,
            // Last, so it compresses the finished response including the
            // headers set above. Nothing in front of the app does this.
            \App\Http\Middleware\CompressResponse::class,
        ]);
        // Sessions must not survive their account being disabled or merged away.
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\TrackUserPresence::class,
        ]);
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) =>
            $request->is('agent', 'agent/*') ? route('agent.login') : route('login')
        );
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'audit.admin' => \App\Http\Middleware\AuditAdminMutation::class,
        ]);
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', 'REMOTE_ADDR'))
        )));
        // The site is behind Cloudflare: its edges are forwarding proxies too, or
        // every visitor would carry a Cloudflare address (App\Support\Cloudflare).
        $trustedProxies = array_values(array_unique(array_merge($trustedProxies, \App\Support\Cloudflare::ranges())));
        $middleware->trustProxies(at: $trustedProxies, headers:
            \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB);
    })
    ->withExceptions()
    ->create();
