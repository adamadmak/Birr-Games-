@extends('layouts.admin')
@section('title', 'Agent Bank Info')

@section('content')
<div class="admin-page-heading">
    <div><p>Agents</p><h1>Agent Bank Info</h1><span>Deposit instructions sent to players are built from these agent accounts.</span></div>
    <a class="admin-view-site" href="{{ route('admin.agents.index') }}"><i class="fa-solid fa-list-ul"></i> Agent list</a>
</div>

<section class="admin-panel report-panel">
    <div class="panel-title"><div><p>Routing</p><h2>Add bank account</h2></div><span>{{ number_format($gateways->count()) }} active gateway(s)</span></div>
    @if($agents->isEmpty() || $gateways->isEmpty())
        <div class="admin-empty">
            @if($agents->isEmpty())Create an agent first.@else No active payment gateway is configured. Add one under Payment gateways.@endif
        </div>
    @else
        <form class="bank-info-form" method="POST" action="{{ route('admin.agents.bank-info.store') }}">
            @csrf
            <label>Agent
                <select name="agent_id" required>
                    <option value="">Select agent</option>
                    @foreach($agents as $agent)<option value="{{ $agent->id }}" @selected(old('agent_id') == $agent->id)>{{ $agent->nickname }}{{ $agent->active ? '' : ' (inactive)' }}</option>@endforeach
                </select>
            </label>
            <label>Gateway
                <select name="payment_gateway_id" required>
                    <option value="">Select gateway</option>
                    @foreach($gateways as $gateway)<option value="{{ $gateway->id }}" @selected(old('payment_gateway_id') == $gateway->id)>{{ $gateway->name }}</option>@endforeach
                </select>
            </label>
            <label>Account name<input name="account_name" value="{{ old('account_name') }}" maxlength="120" required placeholder="Name on the account"></label>
            <label>Account number<input name="account_number" value="{{ old('account_number') }}" maxlength="60" required placeholder="Account or wallet number"></label>
            <label>Notes<input name="notes" value="{{ old('notes') }}" maxlength="255" placeholder="Optional internal note"></label>
            <button type="submit"><i class="fa-solid fa-plus"></i> Add account</button>
        </form>
    @endif
</section>

<section class="admin-panel report-panel">
    <div class="panel-title"><div><p>Directory</p><h2>All bank accounts</h2></div><span>{{ number_format($accounts->total()) }} account(s)</span></div>

    <form class="order-ops-filter" method="GET" action="{{ route('admin.agents.bank-info') }}">
        <input name="search" value="{{ $search }}" placeholder="Agent, gateway, account name or number">
        <select name="agent_id" aria-label="Filter by agent">
            <option value="">All agents</option>
            @foreach($agents as $agent)<option value="{{ $agent->id }}" @selected((int) $agentId === (int) $agent->id)>{{ $agent->nickname }}</option>@endforeach
        </select>
        <select name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
        </select>
        <button type="submit">Filter</button>
        <a href="{{ route('admin.agents.bank-info') }}">Reset</a>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>#</th><th>Agent</th><th>Gateway</th><th>Account name</th><th>Account number</th><th>Notes</th><th>Agent balance</th><th>Status</th><th>Added</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($accounts as $account)
                <tr>
                    <td>{{ $account->id }}</td>
                    <td><strong>{{ $account->agent?->nickname ?: '-' }}</strong><span class="cell-subtitle">{{ $account->agent?->phone }}{{ $account->agent && ! $account->agent->active ? ' · inactive agent' : '' }}</span></td>
                    <td>{{ $account->gateway?->name ?: '-' }}@if($account->gateway?->code)<span class="cell-subtitle">{{ strtoupper($account->gateway->code) }}</span>@endif</td>
                    <td>{{ $account->account_name }}</td>
                    <td><code>{{ $account->account_number }}</code></td>
                    <td>{{ $account->notes ?: '-' }}</td>
                    <td><strong>{{ \App\Support\Money::format($account->agent?->balance, 2) }}</strong></td>
                    <td><span class="status-badge {{ $account->active ? 'won' : '' }}">{{ $account->active ? 'Active' : 'Inactive' }}</span></td>
                    <td>{{ $account->created_at?->format('M j, Y') }}</td>
                    <td>
                        <div class="admin-row-actions">
                            <form method="POST" action="{{ route('admin.agents.bank-info.toggle', $account) }}">@csrf @method('PATCH')
                                <button type="submit">{{ $account->active ? 'Disable' : 'Enable' }}</button>
                            </form>
                            {{-- Safe to remove: a deposit copies the account name and number onto
                                 the order as it is taken, so nothing on record points back here. --}}
                            <form method="POST" action="{{ route('admin.agents.bank-info.destroy', $account) }}"
                                  data-confirm="Delete {{ $account->gateway?->name }} {{ $account->account_number }} for {{ $account->agent?->nickname }}? Deposits already taken through it keep the details they were taken with." >@csrf @method('DELETE')
                                <button type="submit" class="danger">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10"><div class="admin-empty">No agent bank accounts match this filter.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('admin.casino._pagination', ['paginator' => $accounts])
</section>

<style>
.bank-info-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end;margin-top:6px}
.bank-info-form label{display:grid;gap:6px;font-size:12px;font-weight:700;color:var(--ink-2)}
.bank-info-form input,.bank-info-form select{border:1px solid var(--line);border-radius:9px;padding:10px 12px;background:var(--surface);font-weight:500;color:var(--ink)}
.bank-info-form button{border:0;border-radius:9px;padding:11px 16px;background:var(--solid);color:#fff;font-weight:700;cursor:pointer}
.order-ops-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0 16px}
.order-ops-filter>input{flex:1;min-width:230px}
.order-ops-filter>input,.order-ops-filter select{border:1px solid var(--line);border-radius:9px;padding:10px 12px;background:var(--surface)}
.order-ops-filter button,.order-ops-filter a{border:0;border-radius:9px;padding:10px 14px;background:var(--solid);color:#fff;text-decoration:none;font-weight:700}
.order-ops-filter a{background:var(--surface);color:var(--ink);border:1px solid var(--line)}
</style>
@endsection
