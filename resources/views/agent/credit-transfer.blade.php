@extends('layouts.agent')
@section('title', 'Credit')
@section('content')
<div class="agent-page-head agent-reference-head"><div><h1>Credit</h1><span>Manage and view your credit transfers.</span></div></div>
<div class="agent-tabs agent-route-tabs">
    <a class="{{ request('tab', 'transfer') === 'transfer' ? 'active' : '' }}" href="{{ route('agent.credit-transfer', ['tab' => 'transfer']) }}">Transfer</a>
    <a class="{{ request('tab') === 'history' ? 'active' : '' }}" href="{{ route('agent.credit-transfer', ['tab' => 'history']) }}">History</a>
</div>

@if(request('tab', 'transfer') === 'transfer')
<section class="agent-panel agent-reference-form">
    <div class="agent-panel-head"><div><h2>Credit Transfer</h2><p>Send available credit to another active agent.</p></div><div class="agent-balance-pill"><small>Available Credit</small><strong>{{ \App\Support\Money::format($agent->balance, 2) }}</strong></div></div>
    <form class="agent-form" method="POST" action="{{ route('agent.credit-transfer.store') }}">@csrf
        <label>Receiver Phone Number<input name="phone" value="{{ old('phone') }}" placeholder="2519..." required><small>Enter the registered phone number of the receiving agent.</small></label>
        <label>Transfer Amount<input type="number" name="amount" value="{{ old('amount') }}" min="1" placeholder="Enter amount" required><small>Amount is entered in Ethiopian Birr.</small></label>
        <label>Transfer Note <span class="agent-optional">Optional</span><textarea name="note" maxlength="500" placeholder="Add a short transfer note">{{ old('note') }}</textarea><small>Maximum 500 characters.</small></label>
        <div class="agent-modal-actions"><a class="agent-button" href="{{ route('agent.dashboard') }}">Cancel</a><button class="agent-button teal" onclick="return confirm('Transfer this credit to the selected agent?')">Transfer Credits</button></div>
    </form>
</section>
@else
<section class="agent-panel">
    <div class="agent-panel-head"><div><h2>Credit Transfer History</h2><p>Review credit sent to and received from other agents.</p></div></div>
    <div class="agent-table-wrap"><table class="agent-table"><thead><tr><th>Date</th><th>Direction</th><th>Agent</th><th>Amount</th><th>Note</th><th>Status</th></tr></thead><tbody>
        @forelse($transfers as $transfer)
        <tr><td>{{ $transfer->created_at?->format('M d, Y h:i A') }}</td><td><span class="agent-badge {{ $transfer->amount >= 0 ? 'success' : 'cancelled' }}">{{ $transfer->amount >= 0 ? 'Received' : 'Sent' }}</span></td><td>{{ $counterpartAgents->get($transfer->source_id)?->nickname ?: 'Agent #'.$transfer->source_id }}</td><td class="{{ $transfer->amount >= 0 ? 'agent-positive' : 'agent-negative' }}">{{ $transfer->amount >= 0 ? '+' : '-' }}{{ \App\Support\Money::format(abs($transfer->amount), 2) }}</td><td>{{ data_get($transfer->metadata, 'note') ?: '—' }}</td><td><span class="agent-badge success">{{ ucfirst($transfer->status) }}</span></td></tr>
        @empty<tr><td colspan="6"><div class="agent-empty">No credit transfers found.</div></td></tr>@endforelse
    </tbody></table></div>
    @if($transfers->hasPages())<div class="agent-pagination">{{ $transfers->links() }}</div>@endif
</section>
@endif
@endsection
