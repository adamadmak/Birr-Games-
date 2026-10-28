@extends('layouts.admin')
@php
    $route = $route ?? 'admin.agents.orders';
    $heading = $heading ?? 'Orders List';
    $intro = $intro ?? 'Review auditable deposit, withdrawal, and manual withdrawal orders.';
    $tabs = $tabs ?? ['deposit'=>'Deposit orders','withdrawal'=>'Withdrawal orders','manual_withdrawal'=>'Manual withdrawals'];
@endphp
@section('title', $heading)
@section('content')
<div class="admin-page-heading"><div><p>Agents</p><h1>{{ $heading }}</h1><span>{{ $intro }}</span></div><a class="admin-view-site" href="{{ route('admin.agents.reports') }}"><i class="fa-solid fa-chart-line"></i> Report transactions</a></div>
<nav class="engine-tabs operation-tabs">@foreach($tabs as $value=>$label)<a class="{{ $type===$value?'active':'' }}" href="{{ route($route,['type'=>$value]) }}">{{ $label }}</a>@endforeach</nav>
<form class="order-ops-filter" method="GET" action="{{ route($route) }}">
    <input type="hidden" name="type" value="{{ $type }}">
    <input name="search" value="{{ $search }}" placeholder="Member, phone, reference, account">
    <select name="status"><option value="">All statuses</option>@foreach(['pending','processing','payout_submitted','success','rejected','cancelled'] as $value)<option value="{{ $value }}" @selected($status===$value)>{{ str($value)->replace('_',' ')->title() }}</option>@endforeach</select>
    <label><input type="checkbox" name="stale" value="1" @checked($stale)> Stale only</label>
    <button type="submit">Filter</button><a href="{{ route($route,['type'=>$type]) }}">Reset</a>
</form>
<section class="admin-panel withdrawal-admin-panel">
    <div class="admin-table-wrap"><table class="admin-table withdrawal-admin-table"><thead><tr><th>#</th><th>Member</th><th>Phone</th><th>Agent</th><th>Gateway</th><th>Amount</th><th>Payout account</th><th>Reference</th><th>Status</th><th>Created</th><th>Processed by</th><th>Action</th></tr></thead><tbody>
    @forelse($orders as $order)
        <tr>
            <td>{{ $order->id }}</td>
            <td><strong>{{ $order->user?->name ?: '-' }}</strong></td>
            <td>{{ $order->user?->phone ?: '-' }}</td>
            <td>{{ $order->agent?->nickname ?: '-' }}</td>
            <td>{{ $order->gateway?->name ?: '-' }}</td>
            <td><strong>{{ \App\Support\Money::format($order->amount) }}</strong>@if($order->commission)<span class="cell-subtitle">Fee {{ \App\Support\Money::format($order->commission) }}</span>@endif</td>
            <td>@if($order->account_number)<strong>{{ $order->account_name }}</strong><span class="cell-subtitle">{{ $order->account_number }}</span>@else - @endif</td>
            <td>@if($order->reference)<code class="admin-ref" title="{{ $order->reference }}">{{ $order->reference }}</code>@else - @endif @if(isset($proofOrderIds[$order->id]))<a class="cell-subtitle" href="{{ route('admin.agents.orders.deposit-proof', $order) }}" target="_blank" rel="noopener">Payment screenshot</a>@endif</td>
            <td><span class="status-badge order-{{ $order->status }} {{ $order->status==='success'?'won':'' }}">{{ str($order->status)->replace('_',' ')->title() }}</span>@if($order->payout_reference)<span class="cell-subtitle">Payout: {{ $order->payout_reference }}</span>@endif</td>
            <td>{{ $order->created_at->format('M j, Y H:i') }}</td>
            <td>{{ $order->processedBy?->name ?: ($order->agent && $order->processed_at ? 'Agent: '.$order->agent->nickname : '-') }}@if($order->processed_at)<span class="cell-subtitle">{{ $order->processed_at->format('M j, H:i') }}</span>@endif</td>
            <td>
                @if(in_array($order->type, ['withdrawal','manual_withdrawal'], true) && $order->status === 'pending')
                    <form class="order-assign-form" method="POST" action="{{ route('admin.agents.orders.assign', $order) }}">@csrf @method('PATCH')
                        <select name="agent_id" required><option value="">Assign agent</option>@foreach($agents as $agent)<option value="{{ $agent->id }}" @selected($order->agent_id===$agent->id)>{{ $agent->nickname }} · {{ \App\Support\Money::format($agent->balance) }}</option>@endforeach</select>
                        <button type="submit">Assign</button>
                    </form>
                @endif
                @if($order->type === 'deposit' && $order->status === 'pending')
                    <div class="withdrawal-admin-actions">
                        <form method="POST" action="{{ route('admin.agents.orders.status', $order) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="success"><button class="approve" type="submit">Approve</button></form>
                        <form method="POST" action="{{ route('admin.agents.orders.status', $order) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="rejected"><input name="notes" maxlength="1000" placeholder="Rejection reason"><button class="reject" type="submit">Reject</button></form>
                    </div>
                @elseif(in_array($order->type, ['withdrawal','manual_withdrawal'], true) && $order->status === 'pending' && $order->agent_id)
                    <span class="cell-subtitle">Assigned agent approves or rejects this payout directly.</span>
                @elseif(in_array($order->type, ['withdrawal','manual_withdrawal'], true) && in_array($order->status, ['processing','payout_submitted'], true))
                    <span class="cell-subtitle">Legacy order: the assigned agent can complete it directly.</span>
                @endif
                <details class="order-event-details"><summary>History ({{ $order->events->count() }})</summary>
                    @foreach($order->events as $event)<div><strong>{{ str($event->event)->replace('_',' ')->title() }}</strong><span>{{ $event->actor_name }} · {{ $event->created_at->format('M j, H:i') }}</span>@if($event->notes)<small>{{ $event->notes }}</small>@endif</div>@endforeach
                </details>
            </td>
        </tr>
    @empty
        <tr><td colspan="12"><div class="admin-empty">No {{ str($type)->replace('_',' ') }} orders found.</div></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $orders->links() }}
</section>
<style>
.order-ops-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:16px 0}.order-ops-filter>input{flex:1;min-width:230px}.order-ops-filter>input,.order-ops-filter select,.order-assign-form select{border:1px solid var(--line);border-radius:9px;padding:10px 12px;background:var(--surface)}.order-ops-filter label{display:flex;align-items:center;gap:7px;padding:9px 12px;border:1px solid var(--line);border-radius:9px;background:var(--surface)}.order-ops-filter button,.order-ops-filter a,.order-assign-form button{border:0;border-radius:9px;padding:10px 14px;background:var(--solid);color:#fff;text-decoration:none;font-weight:700}.order-ops-filter a{background:var(--surface);color:var(--ink);border:1px solid var(--line)}.order-assign-form{display:flex;gap:6px;margin-bottom:8px}.order-event-details{margin-top:8px;min-width:210px}.order-event-details summary{cursor:pointer;color:var(--primary);font-weight:700}.order-event-details div{display:grid;gap:2px;padding:8px 0;border-bottom:1px solid var(--line)}.order-event-details span,.order-event-details small{font-size:11px;color:var(--muted);white-space:normal}
</style>
@endsection
