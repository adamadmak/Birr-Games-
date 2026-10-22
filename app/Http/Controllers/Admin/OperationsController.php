<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminActivityLog;
use App\Models\Agent;
use App\Models\AgentOrder;
use App\Models\AgentOrderEvent;
use App\Models\TelegramBroadcast;
use App\Services\WalletReconciliationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationsController
{
    public function index(WalletReconciliationService $reconciliation): View
    {
        $staleBefore = now()->subMinutes((int) config('operations.stale_order_minutes', 30));

        return view('admin.operations.index', [
            'metrics' => [
                'pending_deposits' => AgentOrder::query()->where('type', 'deposit')->where('status', 'pending')->count(),
                'pending_withdrawals' => AgentOrder::query()->whereIn('type', ['withdrawal', 'manual_withdrawal'])->where('status', 'pending')->count(),
                'stale_orders' => AgentOrder::query()->where('status', 'pending')->where('created_at', '<=', $staleBefore)->count(),
                'unassigned_withdrawals' => AgentOrder::query()->whereIn('type', ['withdrawal', 'manual_withdrawal'])->where('status', 'pending')->whereNull('agent_id')->count(),
                'failed_broadcasts' => TelegramBroadcast::query()->where('failed_count', '>', 0)->count(),
                'active_agents' => Agent::query()->where('active', true)->count(),
                // Reconciliation aggregates the entire ledger. Recomputing that just to
                // print one number made this dashboard slower with every transaction ever
                // taken, so the count is cached and the Reconciliation page stays live.
                'wallet_anomalies' => $reconciliation->cachedAnomalyCount(),
                'online_users' => \App\Http\Middleware\TrackUserPresence::getOnlineCount(),
            ],
            'staleBefore' => $staleBefore,
            'orders' => AgentOrder::query()
                ->where('status', 'pending')
                ->with(['user', 'agent', 'gateway'])
                ->oldest()
                ->limit(20)
                ->get(),
            'broadcasts' => TelegramBroadcast::query()
                ->where(fn ($query) => $query->whereIn('status', ['queued', 'sending'])->orWhere('failed_count', '>', 0))
                ->latest()
                ->limit(8)
                ->get(),
            'recentEvents' => AgentOrderEvent::query()->with('order.user')->latest('created_at')->limit(12)->get(),
            'onlineUsers' => \App\Http\Middleware\TrackUserPresence::getOnlineUsers(),
        ]);
    }

    public function audit(Request $request): View
    {
        $logs = AdminActivityLog::query()
            ->with('user')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->trim()->toString()).'%';
                $query->where(fn ($nested) => $nested
                    ->where('action', 'like', $term)
                    ->orWhere('route_name', 'like', $term)
                    ->orWhere('path', 'like', $term)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term)));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('response_status', $request->integer('status')))
            ->latest('created_at')
            ->paginate(40)
            ->withQueryString();

        return view('admin.operations.audit', compact('logs'));
    }

    public function reconciliation(WalletReconciliationService $reconciliation): View
    {
        return view('admin.operations.reconciliation', ['report' => $reconciliation->inspect()]);
    }
}
