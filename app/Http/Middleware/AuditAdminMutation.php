<?php

namespace App\Http\Middleware;

use App\Models\AdminActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuditAdminMutation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) || !$request->user()?->isAdmin()) {
            return $next($request);
        }

        $route = $request->route();
        $parameters = collect($route?->parameters() ?? []);
        $target = $parameters->first(fn ($value) => is_object($value) && method_exists($value, 'getKey'));
        $context = collect($request->all())
            ->map(fn ($value, $key) => $this->isSensitive((string) $key)
                ? '[redacted]'
                : (is_scalar($value) || $value === null ? $value : '[complex value]'))
            ->take(40)
            ->all();

        try {
            AdminActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => $route?->getName() ?: $request->method().' '.$request->path(),
                'route_name' => $route?->getName(),
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'target_type' => $target ? $target::class : null,
                'target_id' => $target?->getKey(),
                'response_status' => 102,
                'ip_address' => $request->ip(),
                'user_agent' => str($request->userAgent())->limit(500)->toString(),
                'context' => ['stage' => 'authorized', ...$context],
            ]);
        } catch (\Throwable $exception) {
            Log::critical('An administrator mutation was blocked because its audit record could not be stored.', [
                'admin_user_id' => $request->user()->id,
                'route_name' => $route?->getName(),
                'exception' => $exception->getMessage(),
            ]);
            abort(503, 'The audit service is unavailable. No administrator change was made.');
        }

        $response = $next($request);

        try {
            AdminActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => ($route?->getName() ?: $request->method().' '.$request->path()).'.completed',
                'route_name' => $route?->getName(),
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'target_type' => $target ? $target::class : null,
                'target_id' => $target?->getKey(),
                'response_status' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => str($request->userAgent())->limit(500)->toString(),
                'context' => ['stage' => 'completed'],
            ]);
        } catch (\Throwable $exception) {
            Log::critical('An administrator mutation has its authorization audit entry but no completion entry.', [
                'admin_user_id' => $request->user()->id,
                'route_name' => $route?->getName(),
                'exception' => $exception->getMessage(),
            ]);
        }

        return $response;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, array_map('strtolower', \App\Http\Controllers\Admin\SettingController::SECRETS), true)) {
            return true;
        }

        return collect(['password', 'token', 'secret', 'api_key', 'private_key', 'credential', 'pass_key', '_key', 'passkey'])
            ->contains(fn (string $needle) => str_contains($key, $needle));
    }
}
