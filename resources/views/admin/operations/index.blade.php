@extends('layouts.admin')
@section('title', 'Operations Center')
@section('content')
<div class="admin-page-heading">
    <div>
        <p>Operations</p>
        <h1>Control center</h1>
        <span>Live queues, exceptions, and recovery signals in one place.</span>
    </div>
    <nav class="ops-heading-actions">
        @if(auth()->user()->hasAdminPermission('audit.view'))
            <a href="{{ route('admin.operations.audit') }}"><i class="fa-solid fa-clock-rotate-left"></i> Audit history</a>
        @endif
        @if(auth()->user()->hasAdminPermission('reconciliation.view'))
            <a href="{{ route('admin.operations.reconciliation') }}"><i class="fa-solid fa-scale-balanced"></i> Reconciliation</a>
        @endif
    </nav>
</div>

<section class="metric-grid ops-metric-grid">
    <a class="metric-card metric-mint" href="{{ route('admin.agents.orders', ['type'=>'deposit','status'=>'pending']) }}">
        <span class="metric-icon"><i class="fa-solid fa-arrow-down"></i></span>
        <p>Pending deposits</p>
        <strong>{{ number_format($metrics['pending_deposits']) }}</strong>
        <small>Awaiting verification</small>
    </a>
    <a class="metric-card metric-orange" href="{{ route('admin.agents.orders', ['type'=>'withdrawal','status'=>'pending']) }}">
        <span class="metric-icon"><i class="fa-solid fa-arrow-up"></i></span>
        <p>Pending withdrawals</p>
        <strong>{{ number_format($metrics['pending_withdrawals']) }}</strong>
        <small>Awaiting payout</small>
    </a>
    <a class="metric-card metric-purple" href="{{ route('admin.agents.orders', ['type'=>'withdrawal','status'=>'pending','stale'=>1]) }}">
        <span class="metric-icon"><i class="fa-solid fa-clock"></i></span>
        <p>Stale orders</p>
        <strong>{{ number_format($metrics['stale_orders']) }}</strong>
        <small>Older than {{ config('operations.stale_order_minutes') }} mins</small>
    </a>
    <a class="metric-card metric-blue" href="{{ route('admin.agents.orders', ['type'=>'withdrawal','status'=>'pending']) }}">
        <span class="metric-icon"><i class="fa-solid fa-user-clock"></i></span>
        <p>Unassigned</p>
        <strong>{{ number_format($metrics['unassigned_withdrawals']) }}</strong>
        <small>Need an agent</small>
    </a>
    <article class="metric-card metric-teal">
        <span class="metric-icon"><i class="fa-solid fa-user-check"></i></span>
        <p>Active agents</p>
        <strong>{{ number_format($metrics['active_agents']) }}</strong>
        <small>Available to process</small>
    </article>
    <a class="metric-card metric-green" href="{{ route('admin.operations.reconciliation') }}">
        <span class="metric-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <p>Wallet anomalies</p>
        <strong>{{ number_format($metrics['wallet_anomalies']) }}</strong>
        <small>Require investigation</small>
    </a>
    <article class="metric-card metric-live">
        <span class="metric-icon"><i class="fa-solid fa-signal"></i></span>
        <p>Online now</p>
        <strong>{{ number_format($metrics['online_users']) }}</strong>
        <small>Active users on platform</small>
    </article>
</section>

<section class="ops-online-section">
    <article class="admin-panel ops-online-panel">
        <div class="panel-title">
            <div>
                <p>Live monitor</p>
                <h2>Online users & game activity</h2>
            </div>
            <span class="ops-live-badge"><i class="fa-solid fa-circle ops-live-dot"></i> Live</span>
        </div>
        @if($onlineUsers->isEmpty())
            <div class="admin-empty" style="padding: 40px 20px;">
                <i class="fa-solid fa-wifi" style="font-size: 28px; color: var(--faint); margin-bottom: 10px; display: block;"></i>
                No users are currently online.
            </div>
        @else
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Current activity</th>
                            <th>Game</th>
                            <th>Last seen</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($onlineUsers as $presence)
                        <tr>
                            <td>
                                <div class="ops-user-cell">
                                    <span class="ops-user-avatar">{{ strtoupper(substr($presence['name'] ?? '?', 0, 1)) }}</span>
                                    <div>
                                        <strong>{{ $presence['name'] ?? 'Unknown' }}</strong>
                                        <span class="cell-subtitle">{{ $presence['handle'] ?? '' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge {{ ($presence['role'] ?? '') === 'admin' ? 'admin' : 'won' }}">
                                    {{ str($presence['role'] ?? 'player')->title() }}
                                </span>
                            </td>
                            <td><strong>{{ $presence['current_page'] ?? 'Browsing' }}</strong></td>
                            <td>
                                @if($presence['current_game'] ?? null)
                                    <span class="ops-game-badge"><i class="fa-solid fa-gamepad"></i> {{ str($presence['current_game'])->replace('-', ' ')->title() }}</span>
                                @else
                                    <span style="color: var(--faint);">—</span>
                                @endif
                            </td>
                            <td>
                                <time>{{ \Illuminate\Support\Carbon::parse($presence['last_active_at'])->diffForHumans(short: true) }}</time>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>
</section>

<section class="ops-grid">
    <article class="admin-panel ops-queue">
        <div class="panel-title">
            <div>
                <p>Action queue</p>
                <h2>Oldest pending orders</h2>
            </div>
            <a href="{{ route('admin.agents.orders', ['status'=>'pending']) }}">Open queue →</a>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Age</th>
                        <th>Type</th>
                        <th>Member</th>
                        <th>Agent</th>
                        <th>Amount</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($orders as $order)
                    <tr class="{{ $order->created_at->lte($staleBefore) ? 'ops-stale-row' : '' }}">
                        <td><strong>{{ $order->created_at->diffForHumans(short: true) }}</strong></td>
                        <td>
                            <span class="status-badge {{ $order->type === 'deposit' ? 'won' : 'admin' }}">
                                {{ str($order->type)->replace('_',' ')->title() }}
                            </span>
                        </td>
                        <td>
                            <strong>{{ $order->user?->name ?: '-' }}</strong>
                            <span class="cell-subtitle">{{ $order->user?->phone ?: '-' }}</span>
                        </td>
                        <td>{{ $order->agent?->nickname ?: 'Unassigned' }}</td>
                        <td><strong>{{ \App\Support\Money::format($order->amount) }}</strong></td>
                        <td>@if($order->reference)<code class="admin-ref" title="{{ $order->reference }}">{{ $order->reference }}</code>@else - @endif</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="admin-empty">
                                <i class="fa-regular fa-folder-open" style="font-size: 24px; color: var(--faint); margin-bottom: 8px; display: block;"></i>
                                No pending operational work.
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </article>

    <article class="admin-panel ops-events">
        <div class="panel-title">
            <div>
                <p>Traceability</p>
                <h2>Recent decisions</h2>
            </div>
        </div>
        <div class="ops-event-list">
            @forelse($recentEvents as $event)
                @php
                    $eventName = strtolower((string)$event->event);
                    $badgeStyle = match(true) {
                        str_contains($eventName, 'cancel') || str_contains($eventName, 'reject') => 'ops-icon-danger',
                        str_contains($eventName, 'approve') || str_contains($eventName, 'success') => 'ops-icon-success',
                        default => 'ops-icon-info',
                    };
                    $iconClass = match(true) {
                        str_contains($eventName, 'cancel') || str_contains($eventName, 'reject') => 'fa-solid fa-circle-xmark',
                        str_contains($eventName, 'approve') || str_contains($eventName, 'success') => 'fa-solid fa-circle-check',
                        default => 'fa-solid fa-clock',
                    };
                @endphp
                <div class="ops-event-item">
                    <span class="event-icon-badge {{ $badgeStyle }}"><i class="{{ $iconClass }}"></i></span>
                    <p>
                        <strong>{{ str($event->event)->replace('_',' ')->title() }}</strong>
                        <small>#{{ $event->agent_order_id }} · {{ $event->order?->user?->name ?: 'Member' }} · {{ $event->actor_name }}</small>
                    </p>
                    <time>{{ $event->created_at->diffForHumans(short: true) }}</time>
                </div>
            @empty
                <div class="admin-empty">
                    <i class="fa-solid fa-timeline" style="font-size: 24px; color: var(--faint); margin-bottom: 8px; display: block;"></i>
                    No order events recorded.
                </div>
            @endforelse
        </div>
    </article>
</section>

<style>
.ops-heading-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.ops-heading-actions a { padding: 9px 14px; border: 1px solid var(--line); border-radius: 10px; color: var(--ink); background: var(--surface); font-size: 13px; font-weight: 650; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; transition: border-color .15s, box-shadow .15s; }
.ops-heading-actions a:hover { border-color: var(--line-strong); box-shadow: var(--shadow-sm); }
.ops-metric-grid a { text-decoration: none; color: inherit; }
.ops-grid { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(320px, 1fr); gap: 20px; margin-top: 22px; }
.ops-queue, .ops-events { padding: 22px; }
.ops-stale-row { background: var(--warn-soft) !important; }
.ops-event-list { display: flex; flex-direction: column; gap: 10px; }
.ops-event-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: var(--surface-2); border: 1px solid var(--line-soft); border-radius: 12px; transition: background-color .15s ease, border-color .15s ease; }
.ops-event-item:hover { background: var(--surface-2); border-color: var(--line); }
.ops-event-item .event-icon-badge { display: grid; place-items: center; width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0; font-size: 13px; }
.ops-icon-success { background: var(--success-soft); color: var(--success); }
.ops-icon-danger { background: var(--danger-soft); color: var(--danger); }
.ops-icon-info { background: var(--info-soft); color: var(--info); }
.ops-event-item p { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; margin: 0; }
.ops-event-item strong { font-size: 13px; font-weight: 650; color: var(--ink); }
.ops-event-item small { color: var(--muted); font-size: 11.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ops-event-item time { font-size: 11.5px; font-weight: 500; color: var(--faint); white-space: nowrap; flex-shrink: 0; }

/* Live online users panel */
.ops-online-section { margin-top: 22px; }
.ops-online-panel { padding: 22px; }
.ops-live-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 99px; background: var(--success-soft); color: var(--success); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.ops-live-dot { font-size: 7px; animation: ops-pulse 1.5s ease-in-out infinite; }
@keyframes ops-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .3; } }
.ops-user-cell { display: flex; align-items: center; gap: 10px; }
.ops-user-avatar { width: 32px; height: 32px; display: grid; place-items: center; border-radius: 9px; background: linear-gradient(135deg, var(--primary-deep), var(--primary)); color: #fff; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.ops-user-cell div { display: flex; flex-direction: column; gap: 1px; }
.ops-user-cell strong { font-size: 13px; font-weight: 650; }
.ops-game-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 8px; background: var(--success-soft); color: var(--success); font-size: 11.5px; font-weight: 650; white-space: nowrap; }
.ops-game-badge i { font-size: 10px; }


@media (max-width: 1050px) {
    .ops-grid { grid-template-columns: 1fr; }
}
@media (max-width: 700px) {
    .ops-heading-actions { width: 100%; }
    .ops-heading-actions a { flex: 1; justify-content: center; }
    .ops-metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
}
</style>
@endsection

