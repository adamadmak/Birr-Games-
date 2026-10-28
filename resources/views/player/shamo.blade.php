@extends('layouts.app')

@section('title', __('Shamo').' - Vamoseone')

@section('content')
<div class="player-account-page shamo-page">
    <header class="player-page-heading">
        <p>{{ __('Campaign rewards') }}</p>
        <h1>{{ __('Shamo') }}</h1>
        <span>{{ __('Join local campaigns and claim awarded Ethiopian Birr prizes.') }}</span>
    </header>


    <section class="shamo-campaigns">
        <div class="wallet-section-title"><div><p>{{ __('Open now') }}</p><h2>{{ __('Active campaigns') }}</h2></div><span>{{ __(':count available', ['count' => $campaigns->count()]) }}</span></div>
        <div class="shamo-campaign-grid">
            @forelse($campaigns as $campaign)
                @php($participation = $campaign->participations->first())
                <article class="shamo-campaign-card">
                    <div class="shamo-card-top"><span class="shamo-mark">&#9737;</span><span class="shamo-live"><i></i> {{ __('Live') }}</span></div>
                    <small>{{ $campaign->ends_at ? __('Ends :when', ['when' => $campaign->ends_at->diffForHumans()]) : __('Always open') }}</small>
                    <h3>{{ $campaign->name }}</h3>
                    <p>{{ $campaign->description }}</p>
                    <div class="shamo-card-stats"><span><small>{{ __('Entry') }}</small><strong>{{ $campaign->entry_cost ? \App\Support\Money::format($campaign->entry_cost) : __('Free') }}</strong></span><span><small>{{ __('Reward pool') }}</small><strong>{{ \App\Support\Money::format($campaign->reward_pool) }}</strong></span><span><small>{{ __('Players') }}</small><strong>{{ number_format($campaign->participations_count) }}</strong></span></div>
                    @if($participation)
                        <button class="shamo-joined-button" type="button" disabled>&#10003; {{ __('Joined') }}</button>
                    @else
                        <form method="POST" action="{{ route('shamo.join', $campaign) }}">@csrf<button class="shamo-join-button" type="submit">{{ __('Join campaign') }}</button></form>
                    @endif
                </article>
            @empty
                <div class="shamo-empty-card"><span>&#9737;</span><h3>{{ __('No active campaigns') }}</h3><p>{{ __('New local campaigns will appear here when published.') }}</p></div>
            @endforelse
        </div>
    </section>

    <section class="shamo-history-section">
        <div class="shamo-history-intro"><span>&#10003;</span><div><h2>{{ __('Claimed Shamo') }}</h2><p>{{ __('Your campaign participation and reward history in one place.') }}</p></div></div>
        <div class="shamo-table-wrap">
            <table class="shamo-table">
                <thead><tr><th>#</th><th>{{ __('Campaign') }}</th><th>{{ __('Position') }}</th><th>{{ __('Prize amount') }}</th><th>{{ __('Status') }}</th><th>{{ __('Created at') }}</th><th>{{ __('Action') }}</th></tr></thead>
                <tbody>
                    @forelse($history as $item)
                        <tr><td>{{ $loop->iteration }}</td><td><strong>{{ $item->campaign->name }}</strong></td><td>{{ $item->position ? '#'.number_format($item->position) : '-' }}</td><td>{{ \App\Support\Money::format($item->prize_amount) }}</td><td><span class="shamo-status status-{{ $item->status }}">{{ __(str($item->status)->title()->toString()) }}</span></td><td>{{ $item->joined_at->format('M j, Y') }}</td><td>@if($item->status === 'awarded')<form method="POST" action="{{ route('shamo.claim', $item) }}">@csrf<button>{{ __('Claim prize') }}</button></form>@else<span>-</span>@endif</td></tr>
                    @empty
                        <tr><td colspan="7"><div class="shamo-table-empty"><span>&#9737;</span><h3>{{ __('No Shamo participation yet') }}</h3><p>{{ __('Join an active campaign to start your history.') }}</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
