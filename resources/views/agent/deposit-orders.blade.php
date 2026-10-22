@extends('layouts.agent')
@section('title', 'Deposit Orders')
@section('content')
<div class="agent-page-head agent-reference-head">
    <div><h1>Deposit Orders</h1><span>Verify player payments and credit approved wallets.</span></div>
</div>

<div class="agent-tabs agent-route-tabs">
    <a class="{{ request('tab','orders') === 'orders' ? 'active' : '' }}" href="{{ route('agent.deposit-orders',['tab'=>'orders']) }}">Deposit Orders</a>
    <a class="{{ request('tab') === 'search' ? 'active' : '' }}" href="{{ route('agent.deposit-orders',['tab'=>'search']) }}">Search Order</a>
    <a class="{{ request('tab') === 'received' ? 'active' : '' }}" href="{{ route('agent.deposit-orders',['tab'=>'received']) }}">Deposits For Me</a>
</div>

@if(request('tab','orders') === 'orders')
    <section class="agent-panel">
        <div class="agent-panel-head">
            <div><h2>Deposit Order List</h2><p>Approve only after the bank reference and payment have been verified.</p></div>
            <form class="agent-inline-filter" method="GET">
                <input type="hidden" name="tab" value="orders">
                <select name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    @foreach(['pending','success','rejected','cancelled'] as $option)
                        <option value="{{ $option }}" @selected($status===$option)>{{ ucfirst($option) }}</option>
                    @endforeach
                </select>
                <button class="agent-button">Refresh</button>
            </form>
        </div>
        <div class="agent-table-wrap">
            <table class="agent-table agent-deposit-table">
                <thead><tr><th>Reference</th><th>Customer</th><th>Amount</th><th>Method</th><th>Receiving Account</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($orders as $order)
                    <tr>
                        <td><code>{{ $order->reference ?: 'Awaiting reference' }}</code></td>
                        <td><strong>{{ $order->user?->name ?: 'Member' }}</strong><br><small>{{ $order->user?->phone ?: ($order->user?->username ? '@'.$order->user->username : '—') }}</small></td>
                        <td><strong>{{ \App\Support\Money::format($order->amount,2) }}</strong></td>
                        <td>{{ $order->gateway?->name ?: 'Agent bank' }}</td>
                        <td><strong>{{ $order->account_name ?: '-' }}</strong><br><small>{{ $order->account_number ?: '-' }}</small></td>
                        <td><span class="agent-badge {{ $order->status }}">{{ ucfirst($order->status) }}</span></td>
                        <td>{{ $order->created_at?->format('M d, Y h:i A') }}</td>
                        <td>
                            @if($order->status === 'pending' && $order->reference)
                                <div class="agent-deposit-actions">
                                    <form method="POST" action="{{ route('agent.deposit-orders.process', $order) }}" data-confirm="Approve this payment and credit the player wallet?" >
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="status" value="success">
                                        <button class="agent-button teal" type="submit">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('agent.deposit-orders.process', $order) }}" data-confirm="Reject this deposit without adding wallet funds?" >
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="status" value="rejected">
                                        <button class="agent-button danger" type="submit">Reject</button>
                                    </form>
                                </div>
                            @elseif($order->status === 'pending')
                                <small>Waiting for player reference</small>
                            @else
                                <small>{{ $order->processed_at?->format('M d, h:i A') ?: '-' }}</small>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="agent-empty">No deposit orders found.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($orders->hasPages())<div class="agent-pagination">{{ $orders->links() }}</div>@endif
    </section>
@elseif(request('tab') === 'search')
    <section class="agent-panel agent-reference-form">
        <div class="agent-panel-head"><div><h2>Search Order</h2><p>Find one of your orders using its bank reference number.</p></div></div>
        <form class="agent-form" method="GET">
            <input type="hidden" name="tab" value="search">
            <label>Reference Number<input name="reference" value="{{ $reference }}" placeholder="Enter reference number" required></label>
            <div class="agent-modal-actions"><button class="agent-button teal">Search</button></div>
        </form>
        @if($reference !== '')
            <div class="agent-search-result">
                @if($searchedOrder)
                    <strong>{{ $searchedOrder->reference ?: 'ORDER-'.$searchedOrder->id }}</strong>
                    <span>{{ $searchedOrder->user?->name }} · {{ \App\Support\Money::format($searchedOrder->amount,2) }}</span>
                    <em class="agent-badge {{ $searchedOrder->status }}">{{ ucfirst($searchedOrder->status) }}</em>
                @else
                    <p>No order matched that reference number.</p>
                @endif
            </div>
        @else
            <div class="agent-empty">Enter an order reference to begin searching.</div>
        @endif
    </section>
@else
    <section class="agent-panel">
        <div class="agent-panel-head"><div><h2>Deposits Made For You</h2><p>View credit transfers made on your behalf by other agents.</p></div><button class="agent-button" type="button" onclick="location.reload()">Refresh</button></div>
        <div class="agent-table-wrap">
            <table class="agent-table">
                <thead><tr><th>Date</th><th>From Agent</th><th>Amount</th><th>Note</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($receivedTransfers as $transfer)
                    <tr><td>{{ $transfer->created_at?->format('M d, Y h:i A') }}</td><td>{{ $counterpartAgents->get($transfer->source_id)?->nickname ?: 'Agent #'.$transfer->source_id }}</td><td class="agent-positive">+{{ \App\Support\Money::format($transfer->amount,2) }}</td><td>{{ data_get($transfer->metadata,'note') ?: '-' }}</td><td><span class="agent-badge success">{{ ucfirst($transfer->status) }}</span></td></tr>
                @empty
                    <tr><td colspan="5"><div class="agent-empty">No deposits made for you yet.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($receivedTransfers->hasPages())<div class="agent-pagination">{{ $receivedTransfers->links() }}</div>@endif
    </section>
@endif
@endsection
