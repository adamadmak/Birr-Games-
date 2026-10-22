@extends('layouts.agent')
@section('title', 'Bank Information')
@section('content')
<div class="agent-page-head agent-reference-head">
    <div><h1>Bank Information</h1><span>Manage your linked bank accounts for secure transactions.</span></div>
    <div class="agent-head-actions"><button class="agent-button teal" type="button" data-modal-open="bank-create">＋ Add New Account</button></div>
</div>

<div class="agent-bank-cards">
    @forelse($accounts as $account)
        <article class="agent-panel agent-bank-card">
            <div class="agent-bank-card-top">
                <div class="agent-provider-icon">{{ strtoupper(substr($account->gateway?->name ?: 'B', 0, 1)) }}</div>
                <div><h2>{{ $account->gateway?->name ?: 'Bank account' }}</h2><span>{{ strtoupper($account->gateway?->code ?: 'ACCOUNT') }}</span></div>
                <form method="POST" action="{{ route('agent.bank-info.toggle', $account) }}">@csrf @method('PATCH')
                    <button class="agent-switch {{ $account->active ? 'active' : '' }}" aria-label="Toggle account" title="{{ $account->active ? 'Deactivate' : 'Activate' }}"><i></i></button>
                </form>
            </div>
            <div class="agent-bank-details">
                <div><small>Account Holder</small><strong>{{ $account->account_name }}</strong></div>
                <div><small>Account Number</small><strong>{{ $account->account_number }}</strong></div>
            </div>
            <div class="agent-bank-card-foot">
                <span class="agent-badge {{ $account->active ? 'success' : 'cancelled' }}">{{ $account->active ? 'Active' : 'Inactive' }}</span>
                <button class="agent-link-button" type="button" data-detail-account data-provider="{{ $account->gateway?->name ?: 'Payment account' }}" data-holder="{{ $account->account_name }}" data-number="{{ $account->account_number }}" data-notes="{{ $account->notes }}" data-active="{{ $account->active ? '1' : '0' }}">View Details →</button>
            </div>
        </article>
    @empty
        <section class="agent-panel agent-empty-wide"><div class="agent-empty-icon">▣</div><h2>No bank accounts found</h2><p>Add your first payment account to receive and process orders.</p></section>
    @endforelse
</div>

<div class="agent-modal" id="bank-create" aria-hidden="true"><div class="agent-modal-card">
    <div class="agent-modal-head"><div><h2>Add New Account</h2><p>Link a payment account to your agent profile.</p></div><button type="button" data-modal-close>×</button></div>
    <form class="agent-form" method="POST" action="{{ route('agent.bank-info.store') }}">@csrf
        <label>Select Bank<select name="payment_gateway_id" required><option value="">Choose a bank</option>@foreach($gateways as $gateway)<option value="{{ $gateway->id }}" @selected(old('payment_gateway_id') == $gateway->id)>{{ $gateway->name }}</option>@endforeach</select></label>
        <label>Account Holder Name<input name="account_name" value="{{ old('account_name') }}" placeholder="Enter account holder name" required></label>
        <label>Account Number<input name="account_number" value="{{ old('account_number') }}" placeholder="Enter account or phone number" required></label>
        <label>Additional Note <small class="unit">Optional</small><textarea name="notes" maxlength="500" placeholder="Add a note about this account">{{ old('notes') }}</textarea></label>
        <div class="agent-modal-actions"><button class="agent-button" type="button" data-modal-close>Cancel</button><button class="agent-button teal">Create Account</button></div>
    </form>
</div></div>

<div class="agent-modal" id="bank-detail" aria-hidden="true"><div class="agent-modal-card compact">
    <div class="agent-modal-head"><div><h2>Account Details</h2><p>Linked payment account information.</p></div><button type="button" data-modal-close>×</button></div>
    <dl class="agent-detail-list"><dt>Provider</dt><dd data-detail-provider></dd><dt>Account Holder</dt><dd data-detail-holder></dd><dt>Account Number</dt><dd data-detail-number></dd><dt>Notes</dt><dd data-detail-notes></dd><dt>Status</dt><dd data-detail-status></dd></dl>
</div></div>
@endsection
@push('scripts')
<script>
(() => {
    const close = modal => { modal?.classList.remove('open'); modal?.setAttribute('aria-hidden', 'true'); };
    const open = modal => { modal?.classList.add('open'); modal?.setAttribute('aria-hidden', 'false'); };
    document.querySelectorAll('[data-modal-open]').forEach(button => button.addEventListener('click', () => open(document.getElementById(button.dataset.modalOpen))));
    document.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => close(button.closest('.agent-modal'))));
    document.querySelectorAll('.agent-modal').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) close(modal); }));
    document.querySelectorAll('[data-detail-account]').forEach(button => button.addEventListener('click', () => {
        const modal = document.getElementById('bank-detail');
        modal.querySelector('[data-detail-provider]').textContent = button.dataset.provider || 'Payment account';
        modal.querySelector('[data-detail-holder]').textContent = button.dataset.holder || '—';
        modal.querySelector('[data-detail-number]').textContent = button.dataset.number || '—';
        modal.querySelector('[data-detail-notes]').textContent = button.dataset.notes || 'No additional notes';
        modal.querySelector('[data-detail-status]').textContent = button.dataset.active === '1' ? 'Active' : 'Inactive'; open(modal);
    }));
    @if($errors->any()) open(document.getElementById('bank-create')); @endif
})();
</script>
@endpush
