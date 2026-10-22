@extends('layouts.admin')
@section('title', 'Prediction Pools')
@section('content')
@php
    $money = fn ($amount) => \App\Support\Money::format($amount, 0);
@endphp

<div class="admin-page-heading">
    <div>
        <p>Growth</p>
        <h1>Prediction pools</h1>
        <span>Publish a pool and it appears at the top of the lobby. Nothing shows there while none is live.</span>
    </div>
</div>

<section class="admin-panel prediction-live-strip">
    @if ($live)
        <span class="prediction-live-dot"></span>
        <div>
            <small>Live on the lobby now</small>
            <strong>{{ $live->home_team }} v {{ $live->away_team }}</strong>
            <em>Closes {{ $live->closes_at->format('M j, H:i') }} · {{ number_format($live->entries()->count()) }} picks</em>
        </div>
        <form method="POST" action="{{ route('admin.predictions.status', $live) }}">
            @csrf @method('PATCH')
            <input type="hidden" name="status" value="closed">
            <button class="prediction-stand-down" type="submit">Take down</button>
        </form>
    @elseif (! empty($expired))
        <span class="prediction-live-dot is-warn"></span>
        <div>
            <small>Published, but not showing</small>
            <strong>{{ $expired->home_team }} v {{ $expired->away_team }} closed {{ $expired->closes_at->diffForHumans() }}</strong>
            <em>Predictions shut at {{ $expired->closes_at->format('M j, H:i') }}, so the lobby hides the card. Edit the closing time to reopen it, or settle the match.</em>
        </div>
    @else
        <span class="prediction-live-dot is-off"></span>
        <div>
            <small>Nothing live</small>
            <strong>The lobby is showing no prediction pool</strong>
            <em>Publish one below when there is a big game on.</em>
        </div>
    @endif
</section>

<section class="admin-panel prediction-form-panel">
    <div class="panel-title"><div><p>New</p><h2>Create a pool</h2></div></div>
    <form class="admin-form prediction-form" method="POST" action="{{ route('admin.predictions.store') }}" enctype="multipart/form-data">
        @csrf
        <label>Competition<input type="text" name="competition" value="{{ old('competition') }}" placeholder="FA Community Shield" required maxlength="120"></label>
        <div>
            <label>Home team<input type="text" name="home_team" value="{{ old('home_team') }}" placeholder="Arsenal" required maxlength="80"></label>
            <label>Away team<input type="text" name="away_team" value="{{ old('away_team') }}" placeholder="Man City" required maxlength="80"></label>
        </div>
        <div>
            <label>Home crest<input type="file" name="home_crest" accept="image/png,image/jpeg,image/webp,image/svg+xml"><small>Optional. Initials are used when there is no crest. Max 512&nbsp;KB.</small></label>
            <label>Away crest<input type="file" name="away_crest" accept="image/png,image/jpeg,image/webp,image/svg+xml"><small class="unit">Optional.</small></label>
        </div>
        <div>
            <label>Prize pool ({{ \App\Support\Money::code() }})<input type="number" name="prize_pool" value="{{ old('prize_pool', 30000) }}" min="0" step="1" required></label>
            <label>Minimum balance ({{ \App\Support\Money::code() }})<input type="number" name="min_deposit" value="{{ old('min_deposit', 0) }}" min="0" step="1" required><small>0 for no requirement.</small></label>
        </div>
        <div>
            <label>Status
                <select name="status">
                    <option value="draft" @selected(old('status') === 'draft')>Draft — not on the lobby</option>
                    <option value="active" @selected(old('status', 'active') === 'active')>Publish now</option>
                </select>
            </label>
        </div>
        <div>
            <label>Predictions close<input type="datetime-local" name="closes_at" value="{{ old('closes_at', now()->addDay()->format('Y-m-d\\TH:i')) }}" min="{{ now()->format('Y-m-d\\TH:i') }}" required><small>Must be in the future or the lobby will not show the card.</small></label>
            <label>Kick-off<input type="datetime-local" name="kicks_off_at" value="{{ old('kicks_off_at', now()->addDay()->addHour()->format('Y-m-d\\TH:i')) }}" min="{{ now()->format('Y-m-d\\TH:i') }}" required><small>Picks must close on or before kick-off.</small></label>
        </div>
        <button type="submit">Create pool</button>
    </form>
</section>

<section class="admin-panel table-panel prediction-table-panel">
    <div class="table-toolbar"><span>{{ number_format($pools->count()) }} pool(s)</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Match</th><th>Prize</th><th>Entry</th><th>Closes</th>
                    <th>Picks</th><th>Status</th><th>Result</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($pools as $pool)
                <tr>
                    <td>
                        {{-- The crests as the lobby will actually serve them. Without this
                             there was no way to tell a pool whose crest never uploaded from
                             one whose crest would not load, since both show initials. --}}
                        <span class="prediction-crest-pair">
                            @foreach (['home', 'away'] as $side)
                                <span class="prediction-crest-chip" title="{{ $pool->crestUrl($side) ? 'Crest uploaded' : 'No crest — initials are used' }}">
                                    @if ($pool->crestUrl($side))
                                        <img src="{{ $pool->crestUrl($side) }}" alt="" width="24" height="24" loading="lazy" decoding="async">
                                    @else
                                        {{ $pool->initials($side) }}
                                    @endif
                                </span>
                            @endforeach
                        </span>
                        <strong>{{ $pool->home_team }} v {{ $pool->away_team }}</strong>
                        <span class="cell-subtitle">{{ $pool->competition }}</span>
                    </td>
                    <td>{{ $money($pool->prize_pool) }}</td>
                    <td>Free</td>
                    <td>
                        {{ $pool->closes_at->format('M j, H:i') }}
                        <span class="cell-subtitle">{{ $pool->closes_at->isPast() ? 'closed' : $pool->closes_at->diffForHumans() }}</span>
                    </td>
                    <td><a href="{{ route('admin.predictions.entries', $pool) }}">{{ number_format($pool->entries_count) }}</a></td>
                    <td>
                        @php $isExpired = $pool->status === 'active' && $pool->closes_at->isPast(); @endphp
                        <span class="status-badge {{ $isExpired ? 'expired' : ($pool->status === 'active' ? 'won' : ($pool->status === 'settled' ? 'admin' : '')) }}"
                              @if($isExpired) title="Published, but the closing time has passed — the lobby hides it" @endif>
                            {{ $isExpired ? 'Expired' : ucfirst($pool->status) }}
                        </span>
                    </td>
                    <td>
                        @if ($pool->settled_at)
                            <strong>{{ $pool->home_score }}&nbsp;–&nbsp;{{ $pool->away_score }}</strong>
                        @else
                            <form class="prediction-settle" method="POST" action="{{ route('admin.predictions.settle', $pool) }}">
                                @csrf
                                <input type="number" name="home_score" min="0" max="50" placeholder="0" required aria-label="Home score">
                                <input type="number" name="away_score" min="0" max="50" placeholder="0" required aria-label="Away score">
                                <button type="submit">Settle</button>
                            </form>
                        @endif
                    </td>
                    <td>
                        <div class="prediction-row-actions">
                            @if ($pool->status !== 'active' && $pool->status !== 'settled')
                                <form method="POST" action="{{ route('admin.predictions.status', $pool) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="active">
                                    <button class="engine-action" type="submit">Publish</button>
                                </form>
                            @elseif ($pool->status === 'active')
                                <form method="POST" action="{{ route('admin.predictions.status', $pool) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="closed">
                                    <button class="engine-action" type="submit">Take down</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.predictions.destroy', $pool) }}" data-confirm="Delete this pool and every prediction in it?" >
                                @csrf @method('DELETE')
                                <button class="engine-action is-danger" type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="admin-empty">No prediction pools yet.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
