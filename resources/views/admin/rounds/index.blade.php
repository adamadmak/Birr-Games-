@extends('layouts.admin')
@section('title', 'Game reports')
@section('content')
<div class="admin-page-heading"><div><p>Casino</p><h1>Game reports</h1><span>@if($from || $to){{ number_format($rounds->total()) }} rounds{{ $from ? ' from '.$from : '' }}{{ $to ? ' to '.$to : '' }}@else Audit every database-backed game round and Birr movement.@endif</span></div></div>
<section class="admin-panel table-panel">
    <div class="table-toolbar"><form method="GET" action="{{ route('admin.rounds.index') }}"><input type="search" name="search" value="{{ $search }}" placeholder="Search player or game"><label class="round-date">From<input type="date" name="from" value="{{ $from }}"></label><label class="round-date">To<input type="date" name="to" value="{{ $to }}"></label><button>Search</button>@if($search || $from || $to)<a href="{{ route('admin.rounds.index') }}">Reset</a>@endif</form><span>{{ number_format($rounds->total()) }} rounds</span></div>
    <div class="admin-table-scroll"><table class="admin-table"><thead><tr><th>Round</th><th>Player</th><th>Game</th><th>Stake</th><th>Payout</th><th>Result</th><th>Time</th></tr></thead><tbody>
    @forelse($rounds as $round)<tr><td>#{{ str_pad($round->id,6,'0',STR_PAD_LEFT) }}</td><td><strong>{{ $round->user?->name }}</strong><small class="cell-subtitle">{{ $round->user?->username ? '@'.$round->user->username : $round->user?->phone }}</small></td><td>{{ str($round->game)->replace('-',' ')->title() }}</td><td>{{ \App\Support\Money::format($round->stake) }}</td><td><strong class="{{ $round->payout > 0 ? 'text-positive' : '' }}">{{ \App\Support\Money::format($round->payout) }}</strong></td><td><span class="status-badge {{ ($round->result['won'] ?? false) ? 'won' : '' }}">{{ ($round->result['won'] ?? false) ? 'Won' : 'Lost' }}</span></td><td>{{ $round->created_at->format('M j, H:i') }}</td></tr>@empty<tr><td colspan="7"><div class="admin-empty">No game rounds found.</div></td></tr>@endforelse
    </tbody></table></div>
    @if($rounds->hasPages())<div class="simple-pagination">@if($rounds->onFirstPage())<span>← Previous</span>@else<a href="{{ $rounds->previousPageUrl() }}">← Previous</a>@endif<strong>Page {{ $rounds->currentPage() }} of {{ $rounds->lastPage() }}</strong>@if($rounds->hasMorePages())<a href="{{ $rounds->nextPageUrl() }}">Next →</a>@else<span>Next →</span>@endif</div>@endif
</section>
@endsection
