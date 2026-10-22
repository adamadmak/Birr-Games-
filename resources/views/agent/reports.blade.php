@extends('layouts.agent')
@section('title', 'Reports')
@section('content')
<div class="agent-page-head agent-reference-head"><div><h1>Reports</h1><span>View aggregated deposit and withdrawal amounts for your agent activity.</span></div></div>

<section class="agent-panel agent-filter-panel">
    <div class="agent-panel-head"><div><h2>Date Range Filter</h2><p>Select an optional range. Reports include successful deposits and completed withdrawals.</p></div><button class="agent-button" type="button" onclick="location.reload()">↻ Refresh</button></div>
    <form class="agent-report-filter" method="GET"><label>From<input type="date" name="from" value="{{ request('from',$from->format('Y-m-d')) }}"></label><span>→</span><label>To<input type="date" name="to" value="{{ request('to',$to->format('Y-m-d')) }}"></label><button class="agent-button teal">Apply</button><a class="agent-button" href="{{ route('agent.reports') }}">Clear</a></form>
</section>

<div class="agent-report-grid">
    <article class="agent-panel agent-report-card deposit"><div class="agent-report-icon">↗</div><div><p>Total Deposit Amount</p><strong>{{ \App\Support\Money::format($depositTotal,2) }}</strong><span>{{ number_format($depositCount) }} {{ \Illuminate\Support\Str::plural('deposit',$depositCount) }} · status success</span></div><a class="agent-button" href="{{ route('agent.reports.export',['type'=>'deposit','from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d')]) }}">⇩ Export</a></article>
    <article class="agent-panel agent-report-card withdrawal"><div class="agent-report-icon">↙</div><div><p>Total Withdrawal Amount</p><strong>{{ \App\Support\Money::format($withdrawalTotal,2) }}</strong><span>{{ number_format($withdrawalCount) }} {{ \Illuminate\Support\Str::plural('withdrawal',$withdrawalCount) }} · status completed</span></div><a class="agent-button" href="{{ route('agent.reports.export',['type'=>'withdrawal','from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d')]) }}">⇩ Export</a></article>
</div>
<div class="agent-range-note">Showing activity from <strong>{{ $from->format('M d, Y') }}</strong> through <strong>{{ $to->format('M d, Y') }}</strong>.</div>
@endsection
