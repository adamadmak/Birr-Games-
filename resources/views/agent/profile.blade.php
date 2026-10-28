@extends('layouts.agent')
@section('title', 'Profile')
@section('content')
<div class="agent-page-head agent-reference-head"><div><h1>Profile</h1><span>Manage your account information and settings.</span></div><div class="agent-head-actions"><button class="agent-button" type="button" onclick="location.reload()">↻ Refresh</button><button class="agent-button teal" type="button" data-modal-open="profile-edit">Edit Profile</button></div></div>

<section class="agent-panel agent-profile-summary">
    <div class="agent-profile-identity"><span>{{ strtoupper(substr($agent->first_name,0,1).substr($agent->last_name,0,1)) }}</span><div><h2>{{ $agent->first_name }} {{ $agent->last_name }}</h2><p>{{ '@'.$agent->nickname }}</p><em class="agent-badge success">{{ $agent->active ? 'Active' : 'Inactive' }}</em></div></div>
    <div class="agent-profile-columns">
        <div><h3>Basic Information</h3><dl><dt>Phone Number</dt><dd>{{ $agent->phone }}</dd><dt>Last online</dt><dd>Just now</dd></dl></div>
        <div><h3>Financial Information</h3><dl><dt>Credit Balance</dt><dd class="agent-profile-value">{{ \App\Support\Money::format($agent->balance,2) }}</dd></dl></div>
        <div><h3>Order Statistics</h3><dl><dt>Pending Orders</dt><dd>{{ number_format($pendingOrders) }}</dd><dt>Completed Orders</dt><dd>{{ number_format($completedOrders) }}</dd></dl></div>
    </div>
</section>

<section class="agent-panel agent-profile-section">
    <div class="agent-panel-head"><div><h2>Bank Accounts</h2><p>Your linked payout and deposit accounts.</p></div><a class="agent-button" href="{{ route('agent.bank-info') }}">Manage Accounts</a></div>
    <div class="agent-profile-bank-grid">@forelse($accounts as $account)<article><div class="agent-provider-icon">{{ strtoupper(substr($account->gateway?->name ?: 'B',0,1)) }}</div><div><h3>{{ $account->gateway?->name ?: 'Bank account' }}</h3><p>{{ $account->account_name }}</p><strong>{{ $account->account_number }}</strong></div><span class="agent-badge {{ $account->active ? 'success' : 'cancelled' }}">{{ $account->active ? 'Active' : 'Inactive' }}</span></article>@empty<div class="agent-empty">No bank accounts linked yet.</div>@endforelse</div>
</section>

<section class="agent-panel agent-telegram-card"><div class="agent-provider-icon telegram">➤</div><div><h2>Telegram Integration</h2><p>Connect your agent account with Telegram for faster notifications and access.</p></div><div><span class="agent-badge cancelled">Not connected</span>@if ($telegramUsername !== '')<a class="agent-button teal" href="https://t.me/{{ $telegramUsername }}" target="_blank" rel="noopener">Connect Telegram</a>@endif</div></section>

<div class="agent-modal" id="profile-edit" aria-hidden="true"><div class="agent-modal-card">
    <div class="agent-modal-head"><div><h2>Edit Profile</h2><p>Update your identity, phone number, or password.</p></div><button type="button" data-modal-close>×</button></div>
    <form class="agent-form" method="POST" action="{{ route('agent.profile.update') }}">@csrf @method('PUT')
        <div class="agent-form-grid"><label>First Name<input name="first_name" value="{{ old('first_name',$agent->first_name) }}" required></label><label>Last Name<input name="last_name" value="{{ old('last_name',$agent->last_name) }}" required></label></div>
        <label>Phone Number<input name="phone" value="{{ old('phone',$agent->phone) }}" required></label>
        <div class="agent-form-grid"><label>New Password<input type="password" name="password" minlength="12"><small>Use at least 12 mixed-case characters, including a number and symbol.</small></label><label>Confirm Password<input type="password" name="password_confirmation" minlength="12"></label></div>
        <div class="agent-modal-actions"><button class="agent-button" type="button" data-modal-close>Cancel</button><button class="agent-button teal">Save Changes</button></div>
    </form>
</div></div>
@endsection
@push('scripts')
<script>(()=>{const m=document.getElementById('profile-edit'),open=()=>{m.classList.add('open');m.setAttribute('aria-hidden','false')},close=()=>{m.classList.remove('open');m.setAttribute('aria-hidden','true')};document.querySelector('[data-modal-open="profile-edit"]')?.addEventListener('click',open);document.querySelectorAll('#profile-edit [data-modal-close]').forEach(b=>b.addEventListener('click',close));m?.addEventListener('click',e=>{if(e.target===m)close()});@if($errors->any()) open(); @endif})();</script>
@endpush
