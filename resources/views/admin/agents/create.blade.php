@extends('layouts.admin')
@section('title', 'Create Agent')
@section('content')
<div class="admin-page-heading"><div><p>Agents</p><h1>Create New Agent</h1><span>Add an agent with identity, security, and commission settings.</span></div></div>

<section class="admin-panel operation-form-card">
    <form class="admin-form" method="POST" action="{{ route('admin.agents.store') }}">@csrf
        <div class="form-section">
            <div class="form-section-head"><i class="fa-solid fa-id-card"></i><div><h3>Identity</h3><p>Who this agent is and how players reach them.</p></div></div>
            <div class="form-grid-2">
                <label>First name<input name="first_name" placeholder="Abebe" required></label>
                <label>Last name<input name="last_name" placeholder="Kebede" required></label>
            </div>
            <div class="form-grid-2">
                <label>Nickname<input name="nickname" pattern="[A-Za-z0-9_-]+" placeholder="abe_agent" required><small>Letters, numbers, - and _ only.</small></label>
                <label>Phone number<input name="phone" placeholder="2519XXXXXXXX" required><small>The agent signs in with this number.</small></label>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-head"><i class="fa-solid fa-lock"></i><div><h3>Security</h3><p>Login credentials for the agent account.</p></div></div>
            <div class="form-grid-2"><label>Password<input type="password" name="password" minlength="12" placeholder="••••••••••••" required><small>12 or more mixed characters.</small></label></div>
        </div>

        <div class="form-section">
            <div class="form-section-head"><i class="fa-solid fa-coins"></i><div><h3>Commission</h3><p>One platform-wide rule, set under Configuration → Agent Payment Config.</p></div></div>
            <div class="form-grid-2">
                <label class="game-option-card"><input type="checkbox" name="commission_enabled" value="1"><span><strong>Enable commission</strong><small class="unit">{{ $commissionRule }}</small></span></label>
            </div>
        </div>

        <div class="operation-form-actions"><a href="{{ route('admin.agents.index') }}">Cancel</a><button data-testid="create-agent-submit"><i class="fa-solid fa-user-plus"></i> Create agent</button></div>
    </form>
</section>
@endsection
