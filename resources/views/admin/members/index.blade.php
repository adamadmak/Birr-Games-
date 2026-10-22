@extends('layouts.admin')
@section('title', 'Members')
@section('content')
<div class="members-page-heading"><div><h1>Members</h1><p>Manage users and their permissions</p></div>@if(auth()->user()->hasAdminPermission('transactions.view'))<a class="admin-view-site" href="{{ route('admin.players.report') }}" data-testid="members-player-report"><i class="fa-solid fa-scale-balanced"></i> Player report</a>@endif</div>

<div class="members-page-toolbar">
    <form method="GET" class="members-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="search" value="{{ $search }}" placeholder="Search members..."><input type="hidden" name="status" value="{{ $status }}"><input type="hidden" name="confirmed" value="{{ $confirmed }}"></form>
    <div><button type="button" class="member-import-button" data-member-modal-open="member-import"><i class="fa-solid fa-file-arrow-up"></i> Import CSV</button><a class="member-export-button" href="{{ route('admin.members.export',request()->only('search','status','confirmed')) }}"><i class="fa-regular fa-file-lines"></i> Export CSV</a><button type="button" class="member-filter-button" data-member-modal-open="member-filter"><i class="fa-solid fa-filter"></i> Member Filter</button></div>
</div>

@if($archivedCount > 0 || $archived)
    <nav class="member-scope-tabs">
        <a class="{{ $archived ? '' : 'active' }}" href="{{ route('admin.members.index', request()->except(['archived', 'page'])) }}">Active members</a>
        <a class="{{ $archived ? 'active' : '' }}" href="{{ route('admin.members.index', array_merge(request()->except(['page']), ['archived' => 1])) }}">Archived <b>{{ number_format($archivedCount) }}</b></a>
    </nav>
@endif

@if($archived)
    <div class="member-duplicate-alert member-archive-note">
        <span><i class="fa-solid fa-box-archive"></i></span>
        <div>
            <strong>Archived members keep their records</strong>
            <small>These accounts cannot sign in and are hidden from the directory, but every wallet transaction, order and round stays attributable. Restore returns the account; permanent deletion is only offered when nothing is left to audit.</small>
        </div>
    </div>
@endif

@if($duplicateGroups > 0)
    <div class="member-duplicate-alert" data-testid="member-duplicate-alert">
        <span><i class="fa-solid fa-clone"></i></span>
        <div>
            <strong>{{ number_format($duplicateGroups) }} phone number(s) have more than one account</strong>
            <small>{{ number_format($duplicateAccounts) }} member records share a number written in different formats (0912…, 912…, 251912…). Merge them with <code>php artisan players:merge-duplicates --apply</code>.</small>
        </div>
        @if($onlyDuplicates)
            <a href="{{ route('admin.members.index') }}">Show all members</a>
        @else
            <a href="{{ route('admin.members.index', ['duplicates' => 1]) }}">Review them</a>
        @endif
    </div>
@endif

<section class="admin-panel reference-members-panel">
    <div class="reference-members-title"><h2>{{ $onlyDuplicates ? 'Duplicate Phone Numbers' : 'All Members' }}</h2><span>{{ number_format($members->total()) }} records</span></div>
    <div class="admin-table-scroll">
        <table class="admin-table reference-members-table">
            <thead><tr>
                @foreach(['first_name'=>'First Name','last_name'=>'Last Name','username'=>'Username','phone'=>'Phone Number','credits'=>'Wallet Balance','withdrawable_balance'=>'Withdrawable Balance','contact_confirmed'=>'Contact Confirmed','active'=>'Wallet Status'] as $key=>$label)
                    <th><a href="{{ request()->fullUrlWithQuery(['sort'=>$key,'direction'=>$sort===$key&&$direction==='asc'?'desc':'asc']) }}">{{ $label }} <i class="fa-solid fa-arrow-down-up-across-line"></i></a></th>
                @endforeach
                <th>Actions</th>
            </tr></thead>
            <tbody>
            @forelse($members as $member)
                <tr>
                    <td><strong>{{ $member->first_name }}</strong></td>
                    <td>{{ $member->last_name ?: '-' }}</td>
                    <td>{{ $member->username ? '@'.$member->username : '-' }}</td>
                    <td>@if($member->phone)<a class="member-phone" href="tel:{{ $member->phone }}">{{ $member->phone }}</a>@else <span class="member-missing-phone" title="This account has no phone number">Missing</span> @endif</td>
                    <td>{{ $member->credits > 0 ? \App\Support\Money::format($member->credits, 2) : '-' }}</td>
                    <td>{{ $member->withdrawable_balance > 0 ? \App\Support\Money::format($member->withdrawable_balance, 2) : '-' }}</td>
                    <td><span class="member-confirmed {{ $member->contact_confirmed?'yes':'no' }}"><i class="fa-regular {{ $member->contact_confirmed?'fa-circle-check':'fa-circle-xmark' }}"></i> {{ $member->contact_confirmed?'Yes':'No' }}</span></td>
                    <td>
                        @if($archived)
                            <span class="member-archived-badge"><i class="fa-solid fa-box-archive"></i> Archived {{ $member->deleted_at?->format('M j, Y') }}</span>
                        @else
                            <form method="POST" action="{{ route('admin.members.status',$member) }}" class="member-table-status">@csrf @method('PATCH')<input type="hidden" name="active" value="0"><label aria-label="Toggle {{ $member->name }} wallet"><input type="checkbox" name="active" value="1" @checked($member->active) onchange="this.form.submit()"><span></span></label></form>
                        @endif
                    </td>
                    <td>
                        @if($archived)
                            @if($canDelete)
                                <div class="member-archive-actions">
                                    <form method="POST" action="{{ route('admin.members.restore',$member) }}">@csrf @method('PATCH')<button type="submit" class="member-restore-button"><i class="fa-solid fa-rotate-left"></i> Restore</button></form>
                                    <button type="button" class="member-purge-button" data-member-modal-open="member-purge-{{ $member->id }}"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            @else
                                <span class="cell-subtitle">Super admin only</span>
                            @endif
                        @else
                            <button type="button" class="member-actions-trigger" data-member-modal-open="member-actions-{{ $member->id }}" aria-label="Actions for {{ $member->name }}"><i class="fa-solid fa-ellipsis"></i></button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="admin-empty">{{ $archived ? 'No archived members.' : 'No members match the selected filters.' }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($members->hasPages())<div class="simple-pagination">@if($members->onFirstPage())<span>← Previous</span>@else<a href="{{ $members->previousPageUrl() }}">← Previous</a>@endif<strong>Page {{ $members->currentPage() }} of {{ $members->lastPage() }}</strong>@if($members->hasMorePages())<a href="{{ $members->nextPageUrl() }}">Next →</a>@else<span>Next →</span>@endif</div>@endif
</section>

<dialog class="member-filter-modal" id="member-filter" data-member-modal aria-labelledby="member-filter-title">
    <form method="GET"><header><div><small>Member filters</small><h2 id="member-filter-title">Filter members</h2></div><button type="button" data-member-modal-close aria-label="Close filters"><i class="fa-solid fa-xmark"></i></button></header><div class="member-filter-body"><input type="hidden" name="search" value="{{ $search }}"><label>Wallet status<select name="status"><option value="">All statuses</option><option value="active" @selected($status==='active')>Active</option><option value="inactive" @selected($status==='inactive')>Inactive</option></select></label><label>Contact confirmation<select name="confirmed"><option value="">All members</option><option value="yes" @selected($confirmed==='yes')>Confirmed</option><option value="no" @selected($confirmed==='no')>Not confirmed</option></select></label></div><footer><a href="{{ route('admin.members.index') }}">Reset</a><button>Apply filters</button></footer></form>
</dialog>

<dialog class="reference-member-modal compact member-import-modal" id="member-import" data-member-modal aria-labelledby="member-import-title" @if($errors->memberImport->any()) data-modal-auto-open @endif>
    <form method="POST" action="{{ route('admin.members.import') }}" enctype="multipart/form-data">
        @csrf
        <header><div><small>Production member data</small><h2 id="member-import-title">Import members from CSV</h2><p>Existing players are matched by phone number. New players receive a locked random password.</p></div><button type="button" data-member-modal-close aria-label="Close import"><i class="fa-solid fa-xmark"></i></button></header>
        <div class="reference-member-modal-body">
            <label class="member-import-dropzone"><i class="fa-solid fa-cloud-arrow-up"></i><strong>Select the members export</strong><span>CSV or TXT, up to 5 MB</span><input type="file" name="members_file" accept=".csv,text/csv,text/plain" required></label>
            @error('members_file', 'memberImport')<p class="member-import-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>@enderror
            <div class="member-import-note"><i class="fa-solid fa-shield-halved"></i><p><strong>Safe re-import</strong><span>Balances and profile fields are updated by phone. Administrator accounts are protected and the source footer rows are ignored.</span></p></div>
        </div>
        <footer><button type="button" class="secondary" data-member-modal-close>Cancel</button><button type="submit"><i class="fa-solid fa-file-import"></i> Import members</button></footer>
    </form>
</dialog>

@foreach($members as $member)
    <dialog class="member-action-sheet" id="member-actions-{{ $member->id }}" data-member-modal aria-labelledby="member-actions-title-{{ $member->id }}"><div class="member-action-sheet-head"><div><small>Member actions</small><h3 id="member-actions-title-{{ $member->id }}">{{ $member->name }}</h3></div><button type="button" data-member-modal-close><i class="fa-solid fa-xmark"></i></button></div><div class="member-action-sheet-list"><button type="button" data-member-modal-open="member-edit-{{ $member->id }}"><i class="fa-solid fa-pen"></i><span><strong>Edit member</strong><small>Identity, limits and confirmation</small></span></button><button type="button" data-member-modal-open="member-deposit-{{ $member->id }}"><i class="fa-solid fa-wallet"></i><span><strong>Deposit amount</strong><small>Add ETB to this wallet</small></span></button><a href="{{ route('admin.members.show',$member) }}"><i class="fa-solid fa-user"></i><span><strong>Member details</strong><small>Wallet and transaction history</small></span></a><button type="button" data-member-modal-open="member-password-{{ $member->id }}"><i class="fa-solid fa-key"></i><span><strong>Reset password</strong><small>Set a secure new password</small></span></button>@if($canDelete && ! $archived)<button type="button" class="is-danger" data-member-modal-open="member-archive-{{ $member->id }}"><i class="fa-solid fa-box-archive"></i><span><strong>Archive member</strong><small>Revoke access, keep the records</small></span></button>@endif</div></dialog>
    @include('admin.members._modals',['member'=>$member, 'canDelete'=>$canDelete, 'archived'=>$archived])
@endforeach
@endsection
