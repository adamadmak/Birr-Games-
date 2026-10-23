@extends('layouts.admin')

@section('title', 'Administrators')

@section('content')
<div class="admin-page-heading">
    <div>
        <p>Management</p>
        <h1>Administrators</h1>
        <span>Manage administrator accounts and access status.</span>
    </div>
</div>

<section class="admin-panel operations-toolbar">
    <form method="GET">
        <input type="search" name="search" value="{{ $search }}" placeholder="Search administrators...">
        <button>Search</button>
    </form>
    <button type="button" class="member-filter-button" data-member-modal-open="administrator-create">
        <i class="fa-solid fa-user-plus"></i>
        Add administrator
    </button>
</section>

<section class="admin-panel report-panel">
    <div class="panel-title">
        <div>
            <p>Clients</p>
            <h2>All administrators</h2>
        </div>
        <span>{{ number_format($administrators->total()) }} {{ \Illuminate\Support\Str::plural('record', $administrators->total()) }}</span>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse($administrators as $administrator)
                    <tr>
                        <td><strong>{{ $administrator->name }}</strong></td>
                        <td>{{ $administrator->email }}</td>
                        <td>{{ $administrator->phone ?: '-' }}</td>
                        <td>{{ \App\Models\User::adminRoles()[$administrator->admin_role ?? \App\Models\User::ADMIN_ROLE_SUPER] ?? 'Custom' }}</td>
                        <td><span class="status-badge {{ $administrator->active ? 'won' : '' }}">{{ $administrator->active ? 'Active' : 'Inactive' }}</span></td>
                        <td>{{ $administrator->created_at->format('M j, Y') }}</td>
                        <td>
                            @if(!$administrator->is(auth()->user()))
                                <form method="POST" action="{{ route('admin.administrators.toggle', $administrator) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button>{{ $administrator->active ? 'Disable' : 'Enable' }}</button>
                                </form>
                            @else
                                Current account
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="admin-empty">No administrators found.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $administrators->links() }}
</section>

<dialog
    id="administrator-create"
    class="reference-member-modal compact"
    data-member-modal
    @if($errors->any()) data-modal-auto-open @endif
    aria-labelledby="administrator-create-title"
>
    <form method="POST" action="{{ route('admin.administrators.store') }}">
        @csrf
        <header>
            <div>
                <h2 id="administrator-create-title">Add administrator</h2>
                <p>Create a secure account with access to the administration dashboard.</p>
            </div>
            <button type="button" data-member-modal-close aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>
        <div class="reference-member-modal-body">
            <div class="reference-form-grid">
                <label>Name<input name="name" value="{{ old('name') }}" autocomplete="name" required autofocus></label>
                <label>Email<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required></label>
                <label>Phone<input name="phone" value="{{ old('phone') }}" autocomplete="tel" placeholder="Optional"></label>
                <label>Role<select name="admin_role" required>@foreach(\App\Models\User::adminRoles() as $value => $label)<option value="{{ $value }}" @selected(old('admin_role') === $value)>{{ $label }}</option>@endforeach</select></label>
                <label>Password<input type="password" name="password" minlength="12" autocomplete="new-password" placeholder="12+ mixed characters" required></label>
            </div>
        </div>
        <footer>
            <button type="button" class="secondary" data-member-modal-close>Cancel</button>
            <button type="submit"><i class="fa-solid fa-user-plus"></i> Create administrator</button>
        </footer>
    </form>
</dialog>
@endsection
