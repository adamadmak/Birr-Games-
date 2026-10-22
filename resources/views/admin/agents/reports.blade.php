@extends('layouts.admin')
@section('title', 'Agent Report Transactions')

@section('content')
@php
    $money = fn ($amount) => \App\Support\Money::format($amount, 2);
    $presetLinks = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'quarter' => 'Last 90 days'];
    $queryWithout = request()->except(['page']);
@endphp

<div class="admin-page-heading">
    <div><p>Agents</p><h1>Report Transactions</h1><span>Settled agent money movement for {{ $filters['from']->format('M j, Y') }} — {{ $filters['to']->format('M j, Y') }}</span></div>
    <a class="admin-view-site" href="{{ route('admin.agents.reports.export', $queryWithout) }}" data-testid="agent-report-export"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
</div>

<section class="report-toolbar">
    <div>
        <span>Report period: {{ $filters['from']->format('M j, Y') }} — {{ $filters['to']->format('M j, Y') }}</span>
        <nav class="range-tabs">
            @foreach ($presetLinks as $value => $label)
                <a class="{{ $filters['preset'] === $value ? 'active' : '' }}" href="{{ route('admin.agents.reports', array_merge(request()->except(['preset', 'from', 'to', 'page']), ['preset' => $value])) }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
    <form class="date-filter" method="GET" action="{{ route('admin.agents.reports') }}">
        <input type="hidden" name="preset" value="custom">
        @foreach(['agent_id', 'type', 'status', 'search', 'per_page'] as $carry)
            @if(request()->filled($carry))<input type="hidden" name="{{ $carry }}" value="{{ request($carry) }}">@endif
        @endforeach
        <label>From<input type="date" name="from" value="{{ $filters['from']->format('Y-m-d') }}"></label>
        <label>To<input type="date" name="to" value="{{ $filters['to']->format('Y-m-d') }}"></label>
        <button type="submit">Search</button>
        <a href="{{ route('admin.agents.reports') }}">Reset</a>
    </form>
</section>

<section class="metric-grid">
    <article class="metric-card metric-blue" data-testid="agent-metric-deposits"><span class="metric-icon"><i class="fa-solid fa-arrow-down-long"></i></span><p>Deposits settled</p><strong>{{ $money($summary['deposit_total']) }}</strong><small>{{ number_format($summary['deposit_count']) }} approved order(s)</small></article>
    <article class="metric-card metric-orange" data-testid="agent-metric-withdrawals"><span class="metric-icon"><i class="fa-solid fa-arrow-up-long"></i></span><p>Withdrawals paid</p><strong>{{ $money($summary['withdrawal_total']) }}</strong><small>{{ number_format($summary['withdrawal_count']) }} completed payout(s)</small></article>
    <article class="metric-card metric-green" data-testid="agent-metric-net"><span class="metric-icon"><i class="fa-solid fa-scale-balanced"></i></span><p>Net settled</p><strong>{{ $money($summary['net_settled']) }}</strong><small>Deposits less withdrawals</small></article>
    <article class="metric-card metric-purple" data-testid="agent-metric-commission"><span class="metric-icon"><i class="fa-solid fa-percent"></i></span><p>Commission earned</p><strong>{{ $money($summary['commission_total']) }}</strong><small>Credited to agent wallets</small></article>
    <article class="metric-card metric-teal" data-testid="agent-metric-topups"><span class="metric-icon"><i class="fa-solid fa-wallet"></i></span><p>Credit top-ups</p><strong>{{ $money($summary['top_up_total']) }}</strong><small>Deductions {{ $money($summary['deduction_total']) }}</small></article>
    <article class="metric-card metric-mint" data-testid="agent-metric-float"><span class="metric-icon"><i class="fa-solid fa-sack-dollar"></i></span><p>Agent float now</p><strong>{{ $money($summary['float_balance']) }}</strong><small>{{ number_format($summary['pending_count']) }} pending · {{ $money($summary['pending_total']) }}</small></article>
</section>

<section class="admin-panel report-panel">
    <div class="panel-title"><div><p>Network</p><h2>Per-agent settlement</h2></div><span>{{ number_format(count($breakdown)) }} agent(s)</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table numeric-table">
            <thead><tr>
                <th>Agent</th><th>Deposits</th><th>Deposit volume</th><th>Withdrawals</th><th>Withdrawal volume</th>
                <th>Net settled</th><th>Commission</th><th>Credit adjustments</th><th>Pending</th><th>Balance now</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($breakdown as $row)
                <tr class="{{ $row['has_activity'] ? '' : 'agent-report-idle' }}">
                    <td><strong>{{ $row['agent']->nickname }}</strong><span class="cell-subtitle">{{ $row['agent']->phone }}{{ $row['agent']->active ? '' : ' · inactive' }}</span></td>
                    <td>{{ number_format($row['deposit_count']) }}</td>
                    <td><strong>{{ $money($row['deposit_total']) }}</strong></td>
                    <td>{{ number_format($row['withdrawal_count']) }}</td>
                    <td><strong>{{ $money($row['withdrawal_total']) }}</strong></td>
                    <td class="{{ $row['net_settled'] < 0 ? 'agent-report-negative' : 'agent-report-positive' }}"><strong>{{ $money($row['net_settled']) }}</strong></td>
                    <td>{{ $money($row['commission_total']) }}</td>
                    <td class="{{ $row['credit_total'] < 0 ? 'agent-report-negative' : '' }}">{{ $money($row['credit_total']) }}</td>
                    <td>{{ number_format($row['pending_count']) }}<span class="cell-subtitle">{{ $money($row['pending_total']) }}</span></td>
                    <td><strong>{{ $money($row['agent']->balance) }}</strong></td>
                    <td><a class="agent-report-link" href="{{ route('admin.agents.reports', array_merge(request()->except(['agent_id', 'page']), ['agent_id' => $row['agent']->id])) }}">Drill down</a></td>
                </tr>
            @empty
                <tr><td colspan="11"><div class="admin-empty">No agents match this report period.</div></td></tr>
            @endforelse
            </tbody>
            @if($breakdown !== [])
                <tfoot><tr>
                    <th>All agents</th>
                    <th>{{ number_format($summary['deposit_count']) }}</th>
                    <th>{{ $money($summary['deposit_total']) }}</th>
                    <th>{{ number_format($summary['withdrawal_count']) }}</th>
                    <th>{{ $money($summary['withdrawal_total']) }}</th>
                    <th>{{ $money($summary['net_settled']) }}</th>
                    <th>{{ $money($summary['commission_total']) }}</th>
                    <th>{{ $money($summary['top_up_total'] - $summary['deduction_total']) }}</th>
                    <th>{{ number_format($summary['pending_count']) }}</th>
                    <th>{{ $money($summary['float_balance']) }}</th>
                    <th></th>
                </tr></tfoot>
            @endif
        </table>
    </div>
</section>

<section class="admin-panel report-panel">
    <div class="panel-title"><div><p>Ledger</p><h2>Agent transactions</h2></div><span>{{ number_format($transactions->total()) }} record(s)</span></div>
    <p class="ledger-explainer">
        <i class="fa-solid fa-circle-info"></i>
        <span>
            <strong>Nothing settles without an agent.</strong>
            A deposit stays pending until the assigned agent approves it; only then is the member credited and the agent's float debited.
            A withdrawal reserves the member's funds when they request it, and the assigned agent must record a payout reference to release it — rejecting or cancelling returns the money.
            Each approval posts two rows, and the Method column says which is which: the member's side reads <em>Agent deposit</em> when the agent funded it from their own float and <em>Agent approved</em> when the player paid a bank and the agent only verified the reference, while the agent float side reads <em>Agent float</em>.
        </span>
    </p>

    <form class="order-ops-filter" method="GET" action="{{ route('admin.agents.reports') }}">
        <input type="hidden" name="preset" value="{{ $filters['preset'] }}">
        <input type="hidden" name="from" value="{{ $filters['from']->format('Y-m-d') }}">
        <input type="hidden" name="to" value="{{ $filters['to']->format('Y-m-d') }}">
        <input name="search" value="{{ $filters['search'] }}" placeholder="Agent, member, phone, transaction id, reason">
        <select name="agent_id" aria-label="Filter by agent">
            <option value="">All agents</option>
            @foreach($agents as $agent)<option value="{{ $agent->id }}" @selected((int) $filters['agent_id'] === (int) $agent->id)>{{ $agent->nickname }}</option>@endforeach
        </select>
        <select name="type" aria-label="Filter by ledger type">
            <option value="">All types</option>
            @foreach($ledgerTypes as $option)<option value="{{ $option }}" @selected($filters['type'] === $option)>{{ str($option)->headline() }}</option>@endforeach
        </select>
        <select name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            @foreach(['success', 'pending', 'failed', 'rejected'] as $option)<option value="{{ $option }}" @selected($filters['status'] === $option)>{{ ucfirst($option) }}</option>@endforeach
        </select>
        <button type="submit">Filter</button>
        <a href="{{ route('admin.agents.reports') }}">Reset</a>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Date</th><th>Agent</th><th>Member</th><th>Type</th><th>Amount</th><th>Status</th><th>Method</th><th>Reason</th><th>Source</th><th>Transaction ID</th></tr></thead>
            <tbody>
            @forelse($transactions as $transaction)
                <tr>
                    <td>{{ $transaction->created_at->format('M j, Y H:i') }}</td>
                    <td><strong>{{ $transaction->agent?->nickname ?: '-' }}</strong>@if($transaction->agent?->phone)<span class="cell-subtitle">{{ $transaction->agent->phone }}</span>@endif</td>
                    <td>
                        @if($transaction->user)
                            <a href="{{ route('admin.members.show', $transaction->user) }}">{{ $transaction->user->name }}</a><span class="cell-subtitle">{{ $transaction->user->phone }}</span>
                        @elseif($transaction->counterparty_name)
                            {{ $transaction->counterparty_name }}<span class="cell-subtitle">{{ $transaction->counterparty_phone }} · settled from float</span>
                        @else
                            -
                        @endif
                    </td>
                    <td>{{ str($transaction->type)->headline() }}</td>
                    <td class="{{ $transaction->amount < 0 ? 'agent-report-negative' : 'agent-report-positive' }}"><strong>{{ ($transaction->amount > 0 ? '+' : '') . $money($transaction->amount) }}</strong></td>
                    <td><span class="status-badge {{ $transaction->status === 'success' ? 'won' : '' }}">{{ ucfirst($transaction->status) }}</span></td>
                    <td>
                        @php $methodHint = \App\Support\LedgerMethod::hint($transaction->method); @endphp
                        <span class="{{ \App\Support\LedgerMethod::isBookkeeping($transaction->method) ? 'ledger-entry-method' : 'ledger-method' }}" @if($methodHint) title="{{ $methodHint }}" @endif>{{ \App\Support\LedgerMethod::label($transaction->method) }}</span>
                    </td>
                    <td><span class="agent-report-reason" title="{{ $transaction->reason }}">{{ $transaction->reason ?: '-' }}</span></td>
                    <td>{{ $transaction->source_type ? str($transaction->source_type)->headline().' #'.$transaction->source_id : '-' }}</td>
                    <td><code>{{ $transaction->transaction_id }}</code></td>
                </tr>
            @empty
                <tr><td colspan="10"><div class="admin-empty">No agent ledger entries in this period.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('admin.casino._pagination', ['paginator' => $transactions])
</section>

<style>
.agent-report-idle{opacity:.62}
.agent-report-positive{color:var(--success)}
.agent-report-negative{color:var(--danger)}
.agent-report-link{color:var(--primary);font-weight:700;text-decoration:none;white-space:nowrap}
.agent-report-reason{display:inline-block;max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom}
.ledger-explainer{margin:14px 24px 0;padding:11px 13px;display:flex;align-items:flex-start;gap:10px;color:var(--ink-2);background:var(--surface-2);border:1px solid var(--line);border-radius:11px;font-size:12px;line-height:1.5}
.ledger-explainer i{margin-top:2px;color:var(--primary)}
.ledger-explainer em{font-style:normal;font-weight:700}
.ledger-entry-method{padding:3px 8px;display:inline-block;color:var(--ink-2);background:var(--surface-3);border-radius:999px;font-size:11px;font-weight:700;cursor:help}
.admin-table tfoot th{background:var(--surface-2);font-weight:800}
.order-ops-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:4px 0 16px}
.order-ops-filter>input{flex:1;min-width:230px}
.order-ops-filter>input,.order-ops-filter select{border:1px solid var(--line);border-radius:9px;padding:10px 12px;background:var(--surface)}
.order-ops-filter button,.order-ops-filter a{border:0;border-radius:9px;padding:10px 14px;background:var(--solid);color:#fff;text-decoration:none;font-weight:700}
.order-ops-filter a{background:var(--surface);color:var(--ink);border:1px solid var(--line)}
</style>
@endsection
