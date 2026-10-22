@extends('layouts.admin')
@section('title', 'Wallet Reconciliation')
@section('content')
@php($anomalyCount = $report['balance_mismatches']->count() + $report['agent_balance_mismatches']->count() + $report['invalid_withdrawable']->count() + $report['order_ledger_anomalies']->count() + $report['commission_anomalies']->count() + $report['payout_control_anomalies']->count())
<div class="admin-page-heading"><div><p>Finance control</p><h1>Wallet reconciliation</h1><span>Read-only comparison of player and agent balances, ledger movements, commissions, and payout controls.</span></div><a class="admin-view-site" href="{{ route('admin.operations.index') }}">← Control center</a></div>
<section class="metric-grid">
    <article class="metric-card metric-blue"><p>Accounts checked</p><strong>{{ number_format($report['users_checked'] + $report['agents_checked']) }}</strong><small>Players and agents</small></article>
    <article class="metric-card {{ $anomalyCount ? 'metric-orange' : 'metric-green' }}"><p>Total anomalies</p><strong>{{ number_format($anomalyCount) }}</strong><small>{{ $anomalyCount ? 'Investigation required' : 'All controls passed' }}</small></article>
    <article class="metric-card metric-purple"><p>Balance mismatches</p><strong>{{ $report['balance_mismatches']->count() + $report['agent_balance_mismatches']->count() }}</strong><small>Wallet versus ledger</small></article>
</section>
@foreach([
    'balance_mismatches' => ['Player balance mismatches',['User','Wallet','Ledger']],
    'agent_balance_mismatches' => ['Agent balance mismatches',['Agent','Balance','Ledger']],
    'invalid_withdrawable' => ['Invalid withdrawable balances',['User','Wallet','Withdrawable']],
    'order_ledger_anomalies' => ['Orders missing ledger links',['Order','Type','Amount / status']],
    'commission_anomalies' => ['Commissions missing ledger entries',['Order','Type','Commission / status']],
    'payout_control_anomalies' => ['Settled withdrawals missing controls',['Order','Type','Amount / status']],
] as $key => [$title,$headers])
<section class="admin-panel ops-reconcile-panel"><div class="panel-title"><div><p>Exception report</p><h2>{{ $title }}</h2></div><span>{{ $report[$key]->count() }}</span></div>
<div class="admin-table-wrap"><table class="admin-table"><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>
@forelse($report[$key] as $row)<tr>
    @if($key === 'balance_mismatches')
        <td><a href="{{ route('admin.members.show',$row['user_id']) }}">#{{ $row['user_id'] }} {{ $row['name'] }}</a></td><td>{{ \App\Support\Money::format($row['wallet_balance']) }}</td><td>{{ \App\Support\Money::format($row['ledger_balance']) }}</td>
    @elseif($key === 'agent_balance_mismatches')
        <td><a href="{{ route('admin.agents.index') }}">#{{ $row['agent_id'] }} {{ $row['name'] }}</a></td><td>{{ \App\Support\Money::format($row['wallet_balance']) }}</td><td>{{ \App\Support\Money::format($row['ledger_balance']) }}</td>
    @elseif($key === 'invalid_withdrawable')
        <td><a href="{{ route('admin.members.show',$row['user_id']) }}">#{{ $row['user_id'] }} {{ $row['name'] }}</a></td><td>{{ \App\Support\Money::format($row['wallet_balance']) }}</td><td>{{ \App\Support\Money::format($row['withdrawable_balance']) }}</td>
    @elseif($key === 'commission_anomalies')
        <td><a href="{{ route('admin.agents.orders',['type'=>$row->type,'search'=>$row->reference]) }}">#{{ $row->id }}</a></td><td>{{ str($row->type)->title() }}</td><td>{{ \App\Support\Money::format($row->commission) }} · {{ ucfirst($row->status) }}</td>
    @else
        <td><a href="{{ route('admin.agents.orders',['type'=>$row->type,'search'=>$row->reference]) }}">#{{ $row->id }}</a></td><td>{{ str($row->type)->title() }}</td><td>{{ \App\Support\Money::format($row->amount) }} · {{ ucfirst($row->status) }}</td>
    @endif
</tr>@empty<tr><td colspan="3"><div class="admin-empty">No exceptions found.</div></td></tr>@endforelse
</tbody></table></div></section>
@endforeach
<style>.ops-reconcile-panel{margin-top:20px}.ops-reconcile-panel a{color:var(--primary);font-weight:700}</style>
@endsection
