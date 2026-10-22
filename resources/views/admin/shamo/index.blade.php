@extends('layouts.admin')
@section('title', 'Shamo Campaigns')
@section('content')
<div class="admin-page-heading"><div><p>Growth</p><h1>Shamo Campaigns</h1><span>Create campaigns, monitor participation, and award Ethiopian Birr prizes.</span></div></div>

<div class="management-grid">
    <section class="admin-panel promotion-list">
        <div class="panel-title"><div><p>Campaign library</p><h2>Published Shamo</h2></div><span>{{ $campaigns->count() }} total</span></div>
        @forelse($campaigns as $campaign)
            <article class="shamo-admin-campaign"><span class="promotion-accent accent-cyan"></span><div><small>{{ strtoupper($campaign->status) }}</small><h3>{{ $campaign->name }}</h3><p>{{ $campaign->description }}</p><b>{{ \App\Support\Money::format($campaign->entry_cost) }} entry · {{ \App\Support\Money::format($campaign->reward_pool) }} pool · {{ $campaign->participations_count }} players</b></div><form method="POST" action="{{ route('admin.shamo.status', $campaign) }}">@csrf @method('PATCH')<select name="status"><option value="draft" @selected($campaign->status==='draft')>Draft</option><option value="active" @selected($campaign->status==='active')>Active</option><option value="closed" @selected($campaign->status==='closed')>Closed</option></select><button>Update</button></form></article>
        @empty<div class="admin-empty">No Shamo campaigns yet.</div>@endforelse
    </section>
    <section class="admin-panel create-panel">
        <div class="panel-title"><div><p>New campaign</p><h2>Create Shamo</h2></div></div>
        <form class="admin-form" method="POST" action="{{ route('admin.shamo.store') }}">@csrf
            <label>Name<input name="name" required></label><label>Description<textarea name="description" rows="4"></textarea></label>
            <div><label>Entry cost<input type="number" name="entry_cost" min="0" value="0" required></label><label>Reward pool<input type="number" name="reward_pool" min="0" value="10000" required></label></div>
            <div><label>Starts<input type="datetime-local" name="starts_at"></label><label>Ends<input type="datetime-local" name="ends_at"></label></div>
            <label>Status<select name="status"><option value="draft">Draft</option><option value="active">Active</option></select></label><button type="submit">Create campaign</button>
        </form>
    </section>
</div>

<section class="admin-panel shamo-admin-table-panel">
    <div class="panel-title"><div><p>Participants</p><h2>Participation and awards</h2></div><span>{{ $participations->count() }} records</span></div>
    <div class="admin-table-scroll"><table class="admin-table"><thead><tr><th>Player</th><th>Campaign</th><th>Status</th><th>Position</th><th>Prize</th><th>Joined</th><th>Award</th></tr></thead><tbody>
        @forelse($participations as $item)<tr><td>{{ $item->user->name }}<span class="cell-subtitle">{{ $item->user->phone ?: ($item->user->username ? '@'.$item->user->username : '—') }}</span></td><td>{{ $item->campaign->name }}</td><td><span class="status-badge {{ in_array($item->status,['awarded','claimed']) ? 'won' : '' }}">{{ str($item->status)->title() }}</span></td><td>{{ $item->position ?: '-' }}</td><td>{{ \App\Support\Money::format($item->prize_amount) }}</td><td>{{ $item->joined_at->format('M j, Y') }}</td><td>@if($item->status !== 'claimed')<form class="shamo-award-form" method="POST" action="{{ route('admin.shamo.award', $item) }}">@csrf @method('PATCH')<input type="number" name="position" min="1" value="{{ $item->position ?: 1 }}" aria-label="Position"><input type="number" name="prize_amount" min="1" value="{{ $item->prize_amount ?: 1000 }}" aria-label="Prize amount (ETB)"><button>Award</button></form>@else Claimed @endif</td></tr>@empty<tr><td colspan="7"><div class="admin-empty">Players will appear after joining a campaign.</div></td></tr>@endforelse
    </tbody></table></div>
</section>
@endsection
