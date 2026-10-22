@extends('layouts.admin')
@section('title', 'Predictions')
@section('content')

<div class="admin-page-heading">
    <div>
        <p>Prediction pool</p>
        <h1>{{ $pool->home_team }} v {{ $pool->away_team }}</h1>
        <span>{{ $pool->competition }} · kick-off {{ $pool->kicks_off_at->format('M j, Y H:i') }}</span>
    </div>
    <a class="admin-view-site" href="{{ route('admin.predictions.index') }}">← All pools</a>
</div>

<section class="admin-chart-grid">
    <article class="admin-panel chart-panel">
        <div class="panel-title">
            <div><p>Spread</p><h2>Most predicted scorelines</h2></div>
            <span class="chart-range">{{ number_format($entries->total()) }} picks</span>
        </div>
        @php $top = $scorelines->max('picks') ?: 1; @endphp
        @forelse ($scorelines as $line)
            <div class="prediction-spread-row">
                <strong>{{ $line->home_score }}&nbsp;–&nbsp;{{ $line->away_score }}</strong>
                <span class="prediction-spread-bar">
                    {{-- Width encodes share of picks, so the shape of the crowd reads at a glance. --}}
                    <i style="width: {{ max(3, round($line->picks / $top * 100)) }}%"></i>
                </span>
                <em>{{ number_format($line->picks) }}</em>
            </div>
        @empty
            <div class="admin-empty">No predictions yet.</div>
        @endforelse
    </article>

    <article class="admin-panel chart-panel">
        <div class="panel-title"><div><p>Result</p><h2>Settlement</h2></div></div>
        @if ($pool->settled_at)
            <div class="quick-stat"><span>Final score</span><strong>{{ $pool->home_score }} – {{ $pool->away_score }}<small>settled {{ $pool->settled_at->diffForHumans() }}</small></strong></div>
            <div class="quick-stat"><span>Correct predictions</span><strong>{{ number_format($pool->entries()->where('is_winner', true)->count()) }}<small>share the pool</small></strong></div>
        @else
            <div class="quick-stat"><span>Status</span><strong>{{ ucfirst($pool->status) }}<small>not settled yet</small></strong></div>
        @endif
        <div class="quick-stat"><span>Prize pool</span><strong>{{ \App\Support\Money::format($pool->prize_pool) }}<small>paid manually, like the tournament</small></strong></div>
    </article>
</section>

<section class="admin-panel table-panel" style="margin-top:20px">
    <div class="table-toolbar"><span>{{ number_format($entries->total()) }} prediction(s)</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Player</th><th>Prediction</th><th>Placed</th><th>Outcome</th></tr></thead>
            <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td>
                        <div class="member-cell">
                            <span>{{ strtoupper(substr($entry->user?->name ?? '?', 0, 1)) }}</span>
                            <p><strong>{{ $entry->user?->name ?? 'Deleted player' }}</strong><small>{{ $entry->user?->phone }}</small></p>
                        </div>
                    </td>
                    <td><strong>{{ $entry->home_score }}&nbsp;–&nbsp;{{ $entry->away_score }}</strong></td>
                    <td>{{ $entry->created_at->format('M j, H:i') }}</td>
                    <td>
                        @if (! $pool->settled_at)
                            <span class="status-badge">Pending</span>
                        @elseif ($entry->is_winner)
                            <span class="status-badge won">Correct</span>
                        @else
                            <span class="status-badge">Missed</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4"><div class="admin-empty">Nobody has predicted this match yet.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($entries->hasPages())<div class="simple-pagination">{{ $entries->links() }}</div>@endif
</section>
@endsection
