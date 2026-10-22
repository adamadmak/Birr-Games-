<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceProductionTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && ! $request->secure()) {
            return redirect()->secure($request->getRequestUri(), 308);
        }

        return $next($request);
    }
}
