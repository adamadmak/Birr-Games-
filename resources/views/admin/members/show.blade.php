@extends('layouts.admin')
@section('title', 'Member Details')
@section('content')
<div class="member-details-heading">
    <div>
        <h1>Member Details</h1>
        <nav class="member-details-tabs" aria-label="Member information">
            <a class="{{ $tab === 'details' ? 'active' : '' }}" href="{{ route('admin.members.show', [$member, 'tab' => 'details']) }}"><i class="fa-regular fa-user"></i> Details</a>
            <a class="{{ $tab === 'gaming' ? 'active' : '' }}" href="{{ route('admin.members.show', [$member, 'tab' => 'gaming']) }}"><i class="fa-solid fa-scale-balanced"></i> Gaming &amp; P&amp;L</a>
            <a class="{{ $tab === 'wallet' ? 'active' : '' }}" href="{{ route('admin.members.show', [$member, 'tab' => 'wallet']) }}"><i class="fa-solid fa-wallet"></i> Wallet History</a>
            <a class="{{ $tab === 'transactions' ? 'active' : '' }}" href="{{ route('admin.members.show', [$member, 'tab' => 'transactions']) }}"><i class="fa-regular fa-circle-dollar-to-slot"></i> Transaction History</a>
        </nav>
    </div>
    <div class="member-details-controls">
        <a href="{{ route('admin.members.index') }}"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <div>
            <button type="button" data-member-modal-open="member-password-{{ $member->id }}">Reset Password</button>
            <button type="button" data-member-modal-open="member-deposit-{{ $member->id }}">Deposit Amount</button>
            @if($canDelete)<button type="button" class="member-archive-trigger" data-member-modal-open="member-archive-{{ $member->id }}"><i class="fa-solid fa-box-archive"></i> Archive</button>@endif
        </div>
    </div>
</div>

<section class="member-profile-hero">
    <div>
        <h2>{{ $member->name }}</h2>
        <div class="member-profile-badges">
            <span><i class="fa-solid fa-at"></i> {{ $member->username ?: 'No username' }}</span>
            <span class="{{ $member->phone ? '' : 'danger' }}"><i class="fa-solid fa-phone"></i> {{ $member->phone ? \App\Support\PhoneNumber::format($member->phone) : 'No phone number' }}</span>
            <span class="{{ $member->active ? 'success' : 'danger' }}">Account {{ $member->active ? 'Active' : 'Inactive' }}</span>
            <span class="{{ $member->contact_confirmed ? 'success' : 'muted' }}">Contact {{ $member->contact_confirmed ? 'Confirmed' : 'Not Confirmed' }}</span>
        </div>
        <small><i class="fa-regular fa-calendar"></i> Member since {{ $member->created_at->format('M j, Y, g:i A') }}</small>
    </div>
    <aside><small>Total Balance</small><strong>{{ \App\Support\Money::format($member->credits, 2) }}</strong></aside>
</section>

@if($tab === 'details')
<div class="member-details-grid">
    <div class="member-details-main">
        <section class="member-detail-card">
            <header><i class="fa-solid fa-wallet"></i><h2>Wallet Overview</h2></header>
            <div class="member-stat-grid">
                <article><i class="fa-solid fa-lock-open"></i><div><small>Withdrawable Balance</small><strong>{{ \App\Support\Money::format($member->withdrawable_balance, 2) }}</strong></div></article>
                <article><i class="fa-solid fa-lock"></i><div><small>Non-withdrawable</small><strong>{{ \App\Support\Money::format(max(0, $member->credits - $member->withdrawable_balance), 2) }}</strong></div></article>
            </div>
        </section>

        <section class="member-detail-card">
            <header><i class="fa-solid fa-clock-rotate-left"></i><h2>Transaction Summary</h2></header>
            <div class="member-stat-grid">
                <article><i class="fa-regular fa-circle-up"></i><div><small>Total Deposits</small><strong>{{ \App\Support\Money::format($successfulDeposits, 2) }}</strong><span>{{ number_format($depositCount) }} successful transactions</span></div></article>
                <article><i class="fa-regular fa-circle-down"></i><div><small>Total Withdrawals</small><strong>{{ \App\Support\Money::format($successfulWithdrawals, 2) }}</strong><span>{{ number_format($withdrawalCount) }} successful transactions</span></div></article>
            </div>
        </section>

        <section class="member-detail-card">
            <header><i class="fa-solid fa-scale-balanced"></i><h2>Accountability Snapshot</h2><a class="member-card-link" href="{{ route('admin.members.show', [$member, 'tab' => 'gaming']) }}">Full P&amp;L <i class="fa-solid fa-arrow-right"></i></a></header>
            <div class="member-stat-grid">
                @php
                    $netCash = $successfulDeposits - $successfulWithdrawals;
                    $houseGgr = $gaming['house_ggr'];
                @endphp
                <article><i class="fa-solid fa-money-bill-trend-up"></i><div><small>Net Cash In</small><strong class="{{ $netCash >= 0 ? 'money-positive' : 'money-negative' }}">{{ \App\Support\Money::format($netCash, 2) }}</strong><span>Deposits less withdrawals</span></div></article>
                <article><i class="fa-solid fa-dice"></i><div><small>Total Staked</small><strong>{{ \App\Support\Money::format($gaming['stake_total'], 2) }}</strong><span>{{ number_format($gaming['bet_count']) }} bets placed</span></div></article>
                <article><i class="fa-solid fa-trophy"></i><div><small>Total Won</small><strong>{{ \App\Support\Money::format($gaming['payout_total'], 2) }}</strong><span>RTP {{ $gaming['rtp'] === null ? '—' : number_format($gaming['rtp'], 1).'%' }}</span></div></article>
                <article><i class="fa-solid fa-building-columns"></i><div><small>House Result</small><strong class="{{ $houseGgr >= 0 ? 'money-positive' : 'money-negative' }}">{{ \App\Support\Money::format($houseGgr, 2) }}</strong><span>{{ $houseGgr >= 0 ? 'Player is down' : 'Player is up' }}</span></div></article>
            </div>
        </section>

        <section class="member-detail-card">
            <header><i class="fa-solid fa-gift"></i><h2>Bonus Activity</h2></header>
            <div class="member-stat-grid">
                <article><i class="fa-solid fa-ticket"></i><div><small>Bonuses Claimed</small><strong>{{ number_format($member->bonus_claims_count) }}</strong><span>Recorded bonus claims</span></div></article>
                <article><i class="fa-solid fa-coins"></i><div><small>Bonus Awarded</small><strong>{{ \App\Support\Money::format($bonusCredits, 2) }}</strong><span>Database-recorded credits</span></div></article>
            </div>
        </section>
    </div>

    <aside class="member-details-side">
        <section class="member-detail-card">
            <header><i class="fa-solid fa-bolt"></i><h2>Limits &amp; Caps</h2></header>
            @php
                /*
                 * A cap of zero is not a small cap — it stops the action outright, and
                 * "ETB 0" among a list of limits does not say so. One player's withdrawal
                 * cap of 0 is what made the withdraw form reject every amount; a dash for
                 * "not set" and a plain figure for "0" read almost the same at a glance.
                 */
                $cap = function ($value, string $action): string {
                    if ($value === null) {
                        return 'No cap';
                    }

                    return \App\Support\Money::exact($value)
                        .((float) $value === 0.0 ? ' — blocks all '.$action : '');
                };
            @endphp
            <div class="member-limit-list">
                <div><span>Withdrawal Cap</span><strong>{{ $cap($member->withdrawal_cap, 'withdrawals') }}</strong></div>
                <div><span>Deposit Cap</span><strong>{{ $cap($member->deposit_cap, 'deposits') }}</strong></div>
                <div><span>Transfer Cap</span><strong>{{ $cap($member->transfer_cap, 'transfers') }}</strong></div>
            </div>
        </section>
        <section class="member-detail-card">
            <header><i class="fa-solid fa-gear"></i><h2>System Settings</h2></header>
            <div class="member-setting-list">
                <div class="blue"><span><i></i> Deposit Setting</span><strong>{{ $member->deposit_cap === null ? 'Default' : 'Custom' }}</strong></div>
                <div class="green"><span><i></i> Withdraw Setting</span><strong>{{ $member->withdrawal_cap === null ? 'Default' : 'Custom' }}</strong></div>
                <div class="violet"><span><i></i> Wallet Access</span><strong>{{ $member->active ? 'Enabled' : 'Disabled' }}</strong></div>
            </div>
        </section>
    </aside>
</div>
@elseif($tab === 'gaming')
@php
    $money = fn ($amount) => \App\Support\Money::format($amount, 2);
    $signed = fn ($amount) => ($amount > 0 ? '+' : ($amount < 0 ? '−' : '')).\App\Support\Money::format(abs($amount), 2);
@endphp
<section class="metric-grid member-gaming-grid">
    <article class="metric-card metric-blue"><span class="metric-icon"><i class="fa-solid fa-arrow-down-to-line"></i></span><p>Deposits</p><strong>{{ $money($successfulDeposits) }}</strong><small>{{ number_format($depositCount) }} settled</small></article>
    <article class="metric-card metric-orange"><span class="metric-icon"><i class="fa-solid fa-arrow-up-from-bracket"></i></span><p>Withdrawals</p><strong>{{ $money($successfulWithdrawals) }}</strong><small>{{ number_format($withdrawalCount) }} paid out</small></article>
    <article class="metric-card metric-purple"><span class="metric-icon"><i class="fa-solid fa-dice"></i></span><p>Staked</p><strong>{{ $money($gaming['stake_total']) }}</strong><small>{{ number_format($gaming['bet_count']) }} bets · avg {{ $money($gaming['average_stake']) }}</small></article>
    <article class="metric-card metric-mint"><span class="metric-icon"><i class="fa-solid fa-trophy"></i></span><p>Won</p><strong>{{ $money($gaming['payout_total']) }}</strong><small>Best win {{ $money($gaming['biggest_win']) }}</small></article>
    <article class="metric-card {{ $gaming['player_net'] >= 0 ? 'metric-green' : 'metric-orange' }}"><span class="metric-icon"><i class="fa-solid fa-user-check"></i></span><p>Player win / loss</p><strong>{{ $signed($gaming['player_net']) }}</strong><small>{{ $gaming['player_net'] >= 0 ? 'Ahead of the house' : 'Behind the house' }}</small></article>
    <article class="metric-card metric-teal"><span class="metric-icon"><i class="fa-solid fa-building-columns"></i></span><p>House GGR</p><strong>{{ $signed($gaming['house_ggr']) }}</strong><small>RTP {{ $gaming['rtp'] === null ? '—' : number_format($gaming['rtp'], 1).'%' }}</small></article>
</section>

<div class="member-details-grid">
    <div class="member-details-main">
        <section class="admin-panel member-history-panel">
            <div class="member-history-heading"><div><h2>Game breakdown</h2><p>Where this member's money moves, by game.</p></div><span>{{ number_format(count($gaming['by_game'])) }} game(s)</span></div>
            <div class="admin-table-scroll">
                <table class="admin-table member-history-table">
                    <thead><tr><th>Game</th><th>Bets</th><th>Staked</th><th>Won</th><th>House GGR</th><th>RTP</th><th>Last bet</th></tr></thead>
                    <tbody>
                    @forelse($gaming['by_game'] as $row)
                        <tr>
                            <td><strong>{{ $row['game'] }}</strong></td>
                            <td>{{ number_format($row['bet_count']) }}</td>
                            <td>{{ $money($row['stake_total']) }}</td>
                            <td>{{ $money($row['payout_total']) }}</td>
                            <td class="{{ $row['house_ggr'] >= 0 ? 'money-positive' : 'money-negative' }}"><strong>{{ $signed($row['house_ggr']) }}</strong></td>
                            <td>
                                @if($row['rtp'] === null)—@else
                                    <div class="player-rtp" title="Return to player {{ number_format($row['rtp'], 1) }}%"><i><em style="width: {{ min(100, round($row['rtp'], 1)) }}%"></em></i><b>{{ number_format($row['rtp'], 1) }}%</b></div>
                                @endif
                            </td>
                            <td>{{ $row['last_bet_at'] ? \Illuminate\Support\Carbon::parse($row['last_bet_at'])->format('M j, Y H:i') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="admin-empty"><strong>No bets recorded yet.</strong><span>This member has not played a round.</span></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <aside class="member-details-side">
        <section class="member-detail-card">
            <header><i class="fa-solid fa-stopwatch"></i><h2>Play Pattern</h2></header>
            <div class="member-limit-list">
                <div><span>Winning rounds</span><strong>{{ number_format($gaming['wins']) }}</strong></div>
                <div><span>Losing rounds</span><strong>{{ number_format($gaming['losses']) }}</strong></div>
                <div><span>Win rate</span><strong>{{ $gaming['win_rate'] === null ? '—' : number_format($gaming['win_rate'], 1).'%' }}</strong></div>
                <div><span>Largest stake</span><strong>{{ $money($gaming['biggest_stake']) }}</strong></div>
                <div><span>First bet</span><strong>{{ $gaming['first_bet_at'] ? \Illuminate\Support\Carbon::parse($gaming['first_bet_at'])->format('M j, Y') : '—' }}</strong></div>
                <div><span>Last bet</span><strong>{{ $gaming['last_bet_at'] ? \Illuminate\Support\Carbon::parse($gaming['last_bet_at'])->format('M j, Y H:i') : '—' }}</strong></div>
            </div>
        </section>

        <section class="member-detail-card">
            <header><i class="fa-solid fa-hourglass-half"></i><h2>Open Orders</h2></header>
            <div class="member-limit-list">
                @forelse($pendingOrders as $order)
                    <div>
                        <span>{{ str($order->type)->headline() }} · {{ $order->created_at->format('M j, H:i') }}</span>
                        <strong>{{ $money($order->amount) }} <small>{{ str($order->status)->replace('_', ' ')->title() }}</small></strong>
                    </div>
                @empty
                    <div><span>No pending deposits or withdrawals</span><strong>—</strong></div>
                @endforelse
            </div>
        </section>
    </aside>
</div>
@elseif($tab === 'wallet')
<section class="admin-panel member-history-panel">
    <div class="member-history-heading"><div><h2>Wallet History</h2><p>Every recorded Birr movement for this member.</p></div><span>{{ number_format($walletHistory->total()) }} records</span></div>
    <div class="admin-table-scroll"><table class="admin-table member-history-table"><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Status</th><th>Method</th><th>Reason</th><th>Transaction ID</th></tr></thead><tbody>
    @forelse($walletHistory as $transaction)<tr><td>{{ $transaction->created_at->format('M j, Y g:i A') }}</td><td><span class="history-type">{{ str($transaction->type)->headline() }}</span></td><td class="{{ $transaction->amount >= 0 ? 'money-positive' : 'money-negative' }}">{{ \App\Support\Money::format($transaction->amount, 2) }}</td><td><span class="history-status {{ $transaction->status }}">{{ ucfirst($transaction->status) }}</span></td><td>{{ str($transaction->method ?: '-')->headline() }}</td><td>{{ $transaction->reason ?: '-' }}</td><td><code>{{ $transaction->transaction_id }}</code></td></tr>@empty<tr><td colspan="7"><div class="admin-empty">No wallet transactions have been recorded.</div></td></tr>@endforelse
    </tbody></table></div>
    {{ $walletHistory->links() }}
</section>
@else
<section class="admin-panel member-history-panel">
    <div class="member-history-heading"><div><h2>Transaction History</h2><p>Database-backed casino rounds and outcomes.</p></div><span>{{ number_format($roundHistory->total()) }} records</span></div>
    <div class="admin-table-scroll"><table class="admin-table member-history-table"><thead><tr><th>Date</th><th>Game</th><th>Stake</th><th>Payout</th><th>Net Result</th><th>Round ID</th></tr></thead><tbody>
    @forelse($roundHistory as $round)@php($net = $round->payout - $round->stake)<tr><td>{{ $round->created_at->format('M j, Y g:i A') }}</td><td><strong>{{ $round->game }}</strong></td><td>{{ \App\Support\Money::format($round->stake, 2) }}</td><td>{{ \App\Support\Money::format($round->payout, 2) }}</td><td class="{{ $net >= 0 ? 'money-positive' : 'money-negative' }}">{{ \App\Support\Money::format($net, 2) }}</td><td>#{{ $round->id }}</td></tr>@empty<tr><td colspan="6"><div class="admin-empty">No casino rounds have been recorded.</div></td></tr>@endforelse
    </tbody></table></div>
    {{ $roundHistory->links() }}
</section>
@endif

@include('admin.members._modals', ['member' => $member, 'canDelete' => $canDelete, 'archived' => false])
@endsection
