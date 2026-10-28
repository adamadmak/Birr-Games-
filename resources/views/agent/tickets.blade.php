@extends('layouts.agent')
@section('title', 'Bet Tickets')
@section('content')
@php
    $pageUrl = fn (int $p) => route('agent.tickets', array_filter(
        array_merge(request()->only(['ticket', 'phone', 'status', 'date']), ['page' => $p]),
        fn ($v) => is_scalar($v) && $v !== ''
    ));
    $badge = fn (string $s) => match ($s) {
        'won', 'win', 'paid', 'cashout', 'cashed_out' => 'success',
        'lost', 'lose', 'loss', 'rejected' => 'rejected',
        'void', 'cancelled', 'canceled', 'refunded' => 'cancelled',
        default => '',
    };
    // Same mask as the receipt: another agent's player keeps their number.
    $masked = fn (?string $phone) => $phone ? preg_replace('/\d(?=(?:\D*\d){4})/', '•', \App\Support\PhoneNumber::format($phone)) : null;
    $filtered = $ticketSearch !== '' || $phone !== '' || $status !== '' || $date;
@endphp

<div class="agent-page-head agent-reference-head">
    <div><h1>Bet Tickets</h1><span>Sportsbook tickets of the players you serve, ready to print at the counter.</span></div>
</div>

@unless($result['ok'])
    <div class="agent-alert error">
        <strong>WinHouse cannot be reached right now.</strong>
        The list below is the wallet's own record of your customers' stakes and payouts, so it has no selections, odds or possible wins, and a receipt printed now has the same gaps. Try again in a few minutes, and tell the administrator if it lasts.
    </div>
@endunless

<form class="agent-toolbar agent-ticket-search" method="GET">
    <input type="search" name="ticket" value="{{ $ticketSearch }}" maxlength="80" placeholder="Ticket number" aria-label="Ticket number">
    <input type="search" name="phone" value="{{ $phone }}" maxlength="40" placeholder="Customer phone, e.g. 0912…" aria-label="Customer phone">
    <select name="status" aria-label="Status">
        <option value="">All statuses</option>
        @foreach($statuses as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach
    </select>
    <input type="date" name="date" value="{{ $date?->toDateString() }}" aria-label="Placed on">
    <button class="agent-button teal" type="submit">Search</button>
    @if($filtered)<a class="agent-button" href="{{ route('agent.tickets') }}">Clear</a>@endif
</form>

<section class="agent-panel">
    <div class="agent-panel-head">
        <div>
            <h2>Ticket List</h2>
            <p>Your customers' tickets. A full ticket number finds any ticket, so a player can bring theirs to you.</p>
        </div>
        <span>
            @if(! $result['ok'])
                {{ number_format($total) }} {{ \Illuminate\Support\Str::plural('ticket', $total) }} in the wallet
            @elseif($sifted)
                {{ number_format($total) }} {{ \Illuminate\Support\Str::plural('ticket', $total) }} among the latest {{ number_format(\App\Services\WinhouseTicketsClient::SCAN_LIMIT) }} at WinHouse
            @else
                {{ number_format($total) }} {{ \Illuminate\Support\Str::plural('ticket', $total) }}{{ $customer ? ' for '.$customer->name : '' }}
            @endif
        </span>
    </div>

    <div class="agent-table-wrap">
        <table class="agent-table agent-ticket-table">
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>Player</th>
                    <th>Placed</th>
                    <th>Type</th>
                    <th>Stake</th>
                    <th>Odds</th>
                    <th>Possible win</th>
                    <th>Status</th>
                    <th>Receipt</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tickets as $ticket)
                    @php
                        $user = $ticket['player_id'] ? $users->get($ticket['player_id']) : null;
                        $isMine = $ticket['player_id'] && $mine->contains($ticket['player_id']);
                        $wallet = $local->get($ticket['id']);
                        $stake = $ticket['stake'] > 0 ? $ticket['stake'] : ($wallet->stake ?? 0);
                        $paid = $ticket['paid'] > 0 ? $ticket['paid'] : ($wallet->paid ?? 0);
                        $shown = $ticket['status'] !== 'open' ? $ticket['status'] : ($wallet->status ?? 'open');
                        $picks = count($ticket['selections']);
                    @endphp
                    <tr>
                        <td>
                            <code>{{ $ticket['id'] }}</code>
                            @if(! $isMine && $exactId !== null && (string) $ticket['id'] === (string) $exactId)<br><small>Found by ticket number</small>@endif
                        </td>
                        <td>
                            @if($user)
                                <strong>{{ $user->name }}</strong>
                                <br><small>{{ $isMine ? ($user->phone ?: '—') : ($masked($user->phone) ?? '—') }}</small>
                            @elseif($ticket['player'] !== '')
                                {{ $ticket['player'] }}
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $ticket['placed_at']?->format('M j, Y H:i') ?? '—' }}</td>
                        <td>
                            {{ $ticket['type'] !== '' ? ucfirst($ticket['type']) : '—' }}
                            @if($picks > 0)<br><small>{{ $picks }} {{ \Illuminate\Support\Str::plural('pick', $picks) }}</small>@endif
                        </td>
                        <td>{{ \App\Support\Money::format($stake, 2) }}</td>
                        <td>{{ $ticket['odds'] > 0 ? number_format($ticket['odds'], 2) : '—' }}</td>
                        <td>
                            <strong>{{ $ticket['possible_win'] > 0 ? \App\Support\Money::format($ticket['possible_win'], 2) : '—' }}</strong>
                            @if($paid > 0)<br><small>Paid {{ \App\Support\Money::format($paid, 2) }}</small>@endif
                        </td>
                        <td><span class="agent-badge {{ $badge($shown) }}">{{ ucfirst(str_replace('_', ' ', $shown)) }}</span></td>
                        <td>
                            @if($counterId && (int) ($ticket['player_id'] ?? 0) === (int) $counterId && $paid > 0)
                                @if(isset($paidOut[(string) $ticket['id']]))
                                    <span class="agent-badge success">Paid out</span>
                                @else
                                    <form method="POST" action="{{ route('agent.tickets.pay-out', $ticket['id']) }}" data-confirm="Pay this customer {{ \App\Support\Money::format($paid, 2) }} in cash? A ticket can be paid only once.">
                                        @csrf
                                        <button class="agent-button" type="submit">Pay out</button>
                                    </form>
                                @endif
                            @endif
                            @if(preg_match(\App\Http\Controllers\AgentTicketController::PRINTABLE_ID, (string) $ticket['id']))
                                <a class="agent-button teal agent-ticket-print" href="{{ route('agent.tickets.print', ['ticket' => $ticket['id'], 'auto' => 1]) }}" target="_blank" rel="noopener" title="Print receipt for ticket {{ $ticket['id'] }}"><svg class="ag-nav-ico" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg> Print</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <div class="agent-empty">
                                @if($phoneMiss && $ticketSearch === '')
                                    None of your customers has the phone number “{{ $phone }}”. Another player's ticket can be found by its full ticket number.
                                @elseif($ticketSearch !== '')
                                    No ticket numbered “{{ $ticketSearch }}”{{ $result['ok'] ? '' : ' in the wallet' }}. Check the number on the slip.
                                @elseif($filtered)
                                    No ticket of your customers matches these filters.
                                @elseif($result['ok'])
                                    No recent tickets from your customers. A player appears here once you have handled a deposit or a withdrawal for them.
                                @else
                                    No sportsbook stakes from your customers have reached the wallet yet.
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($lastPage > 1)
        <nav class="agent-pagination agent-ticket-pager" aria-label="Ticket pages">
            @if($page > 1)<a class="agent-button" href="{{ $pageUrl($page - 1) }}" rel="prev">Previous</a>@else<span class="agent-button" aria-disabled="true">Previous</span>@endif
            <span>Page {{ $page }} of {{ $lastPage }}</span>
            @if($page < $lastPage)<a class="agent-button" href="{{ $pageUrl($page + 1) }}" rel="next">Next</a>@else<span class="agent-button" aria-disabled="true">Next</span>@endif
        </nav>
    @endif
</section>
@endsection
