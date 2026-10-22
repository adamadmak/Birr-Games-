<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->isAdmin() && $user->active, 403, 'Active administrator access is required.');

        $routeName = (string) $request->route()?->getName();
        $permission = match (true) {
            // Names players and shows who is mid-session. Every other member surface
            // sits behind members.manage; this one reached the default and was
            // therefore open to every admin role, marketing included.
            $routeName === 'admin.online-players' => 'members.manage',
            $routeName === 'admin.operations.audit' => 'audit.view',
            $routeName === 'admin.operations.reconciliation' => 'reconciliation.view',
            str_starts_with($routeName, 'admin.operations.') => 'operations.view',
            str_starts_with($routeName, 'admin.administrators.') => 'administrators.manage',
            $routeName === 'admin.members.deposit' => 'members.deposit',
            $routeName === 'admin.members.password' => 'members.credentials',
            in_array($routeName, ['admin.members.destroy', 'admin.members.restore', 'admin.members.purge'], true) => 'members.delete',
            str_starts_with($routeName, 'admin.members.') => 'members.manage',
            str_starts_with($routeName, 'admin.settings.') => 'settings.manage',
            str_starts_with($routeName, 'admin.payment-gateways.') => 'payments.manage',
            str_starts_with($routeName, 'admin.transactions.') => 'transactions.view',
            str_starts_with($routeName, 'admin.players.') => 'transactions.view',
            in_array($routeName, [
                'admin.agents.balance', 'admin.agents.orders', 'admin.agents.orders.status',
                'admin.agents.orders.assign', 'admin.agents.orders.payout-proof',
                'admin.agents.withdrawals', 'admin.agents.reports', 'admin.agents.reports.export',
            ], true) => 'agents.finance',
            str_starts_with($routeName, 'admin.agents.') => 'agents.manage',
            str_starts_with($routeName, 'admin.game-engine.'),
            str_starts_with($routeName, 'admin.casino.'),
            str_starts_with($routeName, 'admin.rounds.') => 'casino.manage',
            str_starts_with($routeName, 'admin.promotions.'),
            str_starts_with($routeName, 'admin.wagering.'),
            str_starts_with($routeName, 'admin.shamo.'),
            str_starts_with($routeName, 'admin.module') => 'promotions.manage',
            str_starts_with($routeName, 'admin.telegram.') => 'telegram.manage',
            default => 'dashboard.view',
        };

        abort_unless($user->hasAdminPermission($permission), 403, 'Your administrator role does not permit this action.');

        return $next($request);
    }
}
