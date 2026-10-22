@extends('layouts.admin')
@section('title', 'Transactions')

@section('content')
@php
    $sortUrl = function (string $field) use ($sort, $direction): string {
        return route('admin.transactions.index', array_merge(
            request()->except(['sort', 'direction', 'page']),
            ['sort' => $field, 'direction' => $sort === $field && $direction === 'asc' ? 'desc' : 'asc']
        ));
    };
    $sortIcon = fn (string $field): string => $sort === $field
        ? ($direction === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down')
        : 'fa-arrow-up-arrow-down';
@endphp

<div class="admin-page-heading transaction-page-heading">
    <div><p>Finance</p><h1>Transactions</h1><span>Review every database-backed Birr ledger movement.</span></div>
    <a class="admin-view-site" href="{{ route('admin.transactions.export', request()->query()) }}"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
</div>

<div class="transaction-page-toolbar">
    <form method="GET" action="{{ route('admin.transactions.index') }}" class="transaction-search-form">
        @foreach(request()->except(['search', 'page']) as $key => $value)
            @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
        @endforeach
        <label><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search transactions..." aria-label="Search transactions"></label>
        <button type="submit" aria-label="Run transaction search"><i class="fa-solid fa-arrow-right"></i></button>
    </form>
    <button type="button" class="transaction-filter-button {{ $hasFilters ? 'active' : '' }}" data-member-modal-open="transaction-filter-modal">
        <i class="fa-solid fa-filter"></i> Transaction Filter
        @if($hasFilters)<span>{{ collect($filters)->filter(fn($value) => $value !== '')->count() }}</span>@endif
    </button>
</div>

<section class="admin-panel transaction-ledger-panel">
    <div class="transaction-ledger-title"><h2>All Transactions</h2><span>{{ number_format($transactions->total()) }} records</span></div>
    <div class="transaction-table-scroll">
        <table class="transaction-reference-table">
            <thead><tr>
                @foreach([
                    'date' => 'Date Time', 'amount' => 'Amount', 'status' => 'Status', 'method' => 'Method',
                    'fee' => 'Fee', 'phone' => 'Phone Number', 'transaction_id' => 'Transaction ID',
                    'type' => 'Transaction Type', 'reason' => 'Reason', 'currency' => 'Currency', 'bank_code' => 'Bank Code'
                ] as $field => $label)
                    <th>
                        @if($field !== 'phone')<a href="{{ $sortUrl($field) }}">{{ $label }} <i class="fa-solid {{ $sortIcon($field) }}"></i></a>@else{{ $label }}@endif
                    </th>
                @endforeach
                <th>Actions</th>
            </tr></thead>
            <tbody>
            @forelse($transactions as $transaction)
                @php
                    $phone = $transaction->user?->phone ?: $transaction->agent?->phone;
                    $providerReference = data_get($transaction->metadata, 'provider_reference', data_get($transaction->metadata, 'reference'));
                    $bank = $transaction->gateway?->code ?: $transaction->bank_code;
                @endphp
                <tr>
                    <td class="transaction-date">{{ $transaction->created_at->format('m/d/Y h:i A') }}</td>
                    <td><strong class="transaction-amount {{ $transaction->amount > 0 ? 'positive' : ($transaction->amount < 0 ? 'negative' : '') }}">{{ $transaction->amount > 0 ? '+' : ($transaction->amount < 0 ? '−' : '') }}{{ \App\Support\Money::format(abs($transaction->amount), 2) }}</strong></td>
                    <td><span class="transaction-status status-{{ strtolower($transaction->status) }}"><i></i>{{ ucfirst($transaction->status) }}</span></td>
                    <td><span @if($hint = \App\Support\LedgerMethod::hint($transaction->method)) title="{{ $hint }}" @endif>{{ \App\Support\LedgerMethod::label($transaction->method) }}</span></td>
                    <td>{{ \App\Support\Money::format($transaction->fee, 2) }}</td>
                    <td>@if($transaction->user && $phone)<a class="transaction-phone" href="{{ route('admin.members.show', $transaction->user) }}">{{ $phone }}</a>@else{{ $phone ?: '-' }}@endif</td>
                    <td><code class="transaction-id" title="{{ $transaction->transaction_id }}">{{ $transaction->transaction_id }}</code></td>
                    <td>{{ str($transaction->type)->headline() }}</td>
                    <td><span class="transaction-reason" title="{{ $transaction->reason }}">{{ $transaction->reason }}</span></td>
                    <td><strong>{{ $transaction->currency ?: 'ETB' }}</strong></td>
                    <td>{{ $bank ?: '-' }}</td>
                    <td><button type="button" class="transaction-detail-button" data-member-modal-open="transaction-detail-{{ $transaction->id }}" aria-label="View transaction {{ $transaction->transaction_id }}"><i class="fa-regular fa-eye"></i></button></td>
                </tr>
            @empty
                <tr><td colspan="12"><div class="transaction-empty"><i class="fa-solid fa-receipt"></i><h3>No transactions found</h3><p>Try changing the search or transaction filters.</p></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('admin.casino._pagination', ['paginator' => $transactions])
</section>

<dialog id="transaction-filter-modal" class="reference-member-modal transaction-filter-modal" data-member-modal>
    <form method="GET" action="{{ route('admin.transactions.index') }}">
        <header><div><h2>Transaction Filter</h2><p>Select your filters here</p></div><button type="button" data-member-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header>
        <div class="reference-member-modal-body transaction-filter-grid">
            <label>Min Amount<input type="number" step="0.01" min="0" name="min_amount" value="{{ $filters['min_amount'] }}" placeholder="Enter min amount"></label>
            <label>Max Amount<input type="number" step="0.01" min="0" name="max_amount" value="{{ $filters['max_amount'] }}" placeholder="Enter max amount"></label>
            <label>Min Fee<input type="number" step="0.01" min="0" name="min_fee" value="{{ $filters['min_fee'] }}" placeholder="Enter min fee"></label>
            <label>Max Fee<input type="number" step="0.01" min="0" name="max_fee" value="{{ $filters['max_fee'] }}" placeholder="Enter max fee"></label>
            <label>Created After<input type="date" name="created_after" value="{{ $filters['created_after'] }}"></label>
            <label>Created Before<input type="date" name="created_before" value="{{ $filters['created_before'] }}"></label>
            <label>Type<select name="type"><option value="">Select type</option>@foreach($types as $option)<option value="{{ $option }}" @selected($filters['type'] === $option)>{{ str($option)->headline() }}</option>@endforeach</select></label>
            <label>Status<select name="status"><option value="">Select status</option>@foreach($statuses as $option)<option value="{{ $option }}" @selected($filters['status'] === $option)>{{ ucfirst($option) }}</option>@endforeach</select></label>
            <label>Reason<select name="reason"><option value="">Select reason</option>@foreach($reasons as $option)<option value="{{ $option }}" @selected($filters['reason'] === $option)>{{ $option }}</option>@endforeach</select></label>
            <label>Transaction ID<input name="transaction_id" value="{{ $filters['transaction_id'] }}" placeholder="Enter transaction id"></label>
            <label>Provider Reference<input name="provider_reference" value="{{ $filters['provider_reference'] }}" placeholder="Enter provider reference"></label>
            <label>Payment Provider<input name="payment_provider" value="{{ $filters['payment_provider'] }}" placeholder="Enter payment provider"></label>
            <label>Member Phone<input name="member_phone" value="{{ $filters['member_phone'] }}" placeholder="Enter member phone"></label>
            @if($filters['search'])<input type="hidden" name="search" value="{{ $filters['search'] }}">@endif
        </div>
        <footer><a class="transaction-reset-link" href="{{ route('admin.transactions.index') }}">Reset Filters</a><button type="submit"><i class="fa-solid fa-check"></i> Apply Filters</button></footer>
    </form>
</dialog>

@foreach($transactions as $transaction)
    @php
        $providerReference = data_get($transaction->metadata, 'provider_reference', data_get($transaction->metadata, 'reference'));
        $phone = $transaction->user?->phone ?: $transaction->agent?->phone;
    @endphp
    <dialog id="transaction-detail-{{ $transaction->id }}" class="reference-member-modal compact transaction-detail-modal" data-member-modal>
        <header><div><small>Ledger record</small><h2>Transaction Details</h2><p>{{ $transaction->transaction_id }}</p></div><button type="button" data-member-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header>
        <div class="transaction-detail-body">
            <article><span>Amount</span><strong>{{ \App\Support\Money::format($transaction->amount, 2) }}</strong></article>
            <article><span>Status</span><strong>{{ ucfirst($transaction->status) }}</strong></article>
            <article><span>Type</span><strong>{{ str($transaction->type)->headline() }}</strong></article>
            <article><span>Method</span><strong>{{ \App\Support\LedgerMethod::label($transaction->method) }}</strong></article>
            <article><span>Member phone</span><strong>{{ $phone ?: '-' }}</strong></article>
            <article><span>Payment provider</span><strong>{{ $transaction->gateway?->name ?: ($transaction->bank_code ?: '-') }}</strong></article>
            <article class="wide"><span>Provider reference</span><strong>{{ $providerReference ?: '-' }}</strong></article>
            <article class="wide"><span>Reason</span><strong>{{ $transaction->reason }}</strong></article>
            <article class="wide"><span>Created</span><strong>{{ $transaction->created_at->format('M j, Y g:i:s A') }}</strong></article>
        </div>
        <footer><button type="button" class="secondary" data-member-modal-close>Close</button></footer>
    </dialog>
@endforeach
@endsection
