@extends('layouts.admin')

@section('title', 'Players online')

@section('content')
<div class="admin-page-heading">
    <div>
        <p>Live activity</p>
        <h1>Players Online</h1>
        <span>Everyone with play activity in the last {{ $window }} minutes, and the game they are in.</span>
    </div>
    <div class="online-head-actions">
        <form method="GET" action="{{ route('admin.online-players') }}">
            <label>Window
                <select name="window" onchange="this.form.submit()">
                    @foreach([5, 15, 30, 60, 180] as $choice)
                        <option value="{{ $choice }}" @selected($window === $choice)>{{ $choice }} min</option>
                    @endforeach
                </select>
            </label>
        </form>
        <a class="admin-view-site" href="{{ route('admin.online-players', ['window' => $window]) }}">Refresh</a>
    </div>
</div>

<div class="online-stat-grid">
    <article class="online-stat total">
        <span><i class="fa-solid fa-users"></i></span>
        <div><strong>{{ number_format($totalOnline) }}</strong><small>Players online</small></div>
    </article>
    <article class="online-stat playing">
        <span><i class="fa-solid fa-gamepad"></i></span>
        <div><strong>{{ number_format($playing) }}</strong><small>Actively betting</small></div>
    </article>
    <article class="online-stat idle">
        <span><i class="fa-regular fa-clock"></i></span>
        <div><strong>{{ number_format($idle) }}</strong><small>Signed in, not in a game</small></div>
    </article>
    <article class="online-stat staked">
        <span><i class="fa-solid fa-coins"></i></span>
        <div><strong>{{ \App\Support\Money::format($staked, 2) }}</strong><small>Staked in this window</small></div>
    </article>
</div>

@if($players->isEmpty())
    <div class="online-empty">
        <i class="fa-regular fa-moon"></i>
        <strong>Nobody is online right now</strong>
        <span>No signed-in player, provider session, or game round in the last {{ $window }} minutes. Widen the window to look further back.</span>
    </div>
@else
    <section class="admin-panel">
        <div class="reference-members-title">
            <h2>Who is playing <small style="font-weight:500;color:var(--muted)">as of {{ $generatedAt->format('H:i:s') }}</small></h2>
            <span>{{ number_format($totalOnline) }} online</span>
        </div>
        <div class="admin-table-scroll">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Player</th>
                        <th>Phone</th>
                        <th>Balance</th>
                        <th>Game</th>
                        <th>Provider</th>
                        <th>Rounds</th>
                        <th>Staked</th>
                        <th>Started</th>
                        <th>Last seen</th>
                        <th>State</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($players as $player)
                        <tr>
                            <td><a href="{{ route('admin.members.show', $player['user_id']) }}">{{ $player['name'] }}</a></td>
                            <td>{{ $player['phone'] ? \App\Support\PhoneNumber::format($player['phone']) : '—' }}</td>
                            <td>{{ \App\Support\Money::format($player['credits'], 2) }}</td>
                            <td><strong>{{ $player['game'] }}</strong><br><small class="online-channel">{{ $player['channel'] }}</small></td>
                            <td>{{ $player['provider'] }}</td>
                            <td>{{ $player['rounds'] > 0 ? number_format($player['rounds']) : '—' }}</td>
                            <td>{{ $player['staked'] > 0 ? \App\Support\Money::format($player['staked'], 2) : '—' }}</td>
                            <td>{{ $player['started_at']?->format('H:i') ?: '—' }}</td>
                            <td>{{ $player['last_seen_at']?->diffForHumans(null, true) }} ago</td>
                            <td>
                                @if($player['is_playing'])
                                    <span class="online-pill is-playing"><i class="fa-solid fa-circle"></i> Playing</span>
                                @else
                                    <span class="online-pill is-idle"><i class="fa-regular fa-circle"></i> Idle</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <p class="online-note">
        <i class="fa-solid fa-circle-info"></i>
        <span>
            Three sources are combined per player: a heartbeat from the page they have open, the provider session they launched, and the rounds they actually bet.
            That means a player browsing the lobby is listed as well as one mid-game, and the game column shows the real title whenever a game is open.
            <strong>Playing</strong> means a game is open or money moved; <strong>Idle</strong> means signed in without a game.
            Stake totals cover in-house rounds inside the window.
        </span>
    </p>
@endif
@endsection
