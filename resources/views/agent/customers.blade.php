@extends('layouts.agent')
@section('title', 'My Customers')
@section('content')
<div class="agent-page-head agent-reference-head">
    <div><h1>My Customers</h1><span>Everyone whose deposits or withdrawals you have handled.</span></div>
</div>

<section class="agent-panel">
    <div class="agent-panel-head">
        <div><h2>Customer List</h2><p>Sorted by most recent activity with you.</p></div>
        <form class="agent-inline-filter" method="GET">
            <input type="search" name="search" value="{{ $search }}" placeholder="Name, username or phone" aria-label="Search customers">
            <button class="agent-button" type="submit">Search</button>
            @if($search !== '')
                <a class="agent-button" href="{{ route('agent.customers') }}">Clear</a>
            @endif
        </form>
        <span>{{ number_format($customers->total()) }} {{ \Illuminate\Support\Str::plural('customer', $customers->total()) }}</span>
    </div>

    <div class="agent-table-wrap">
        <table class="agent-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Wallet</th>
                    <th>Deposited with you</th>
                    <th>Withdrawn with you</th>
                    <th>Orders</th>
                    <th>Last activity</th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                    <tr>
                        <td>
                            <strong>{{ $customer->name }}</strong>
                            <br><small>{{ $customer->phone ?: ($customer->username ? '@'.$customer->username : '—') }}</small>
                            @unless($customer->active)<br><span class="agent-badge rejected">Disabled</span>@endunless
                        </td>
                        <td>{{ \App\Support\Money::format($customer->credits, 2) }}</td>
                        <td><strong>{{ \App\Support\Money::format($customer->agent_deposited, 2) }}</strong></td>
                        <td>{{ \App\Support\Money::format($customer->agent_withdrawn, 2) }}</td>
                        <td>
                            {{ number_format((int) $customer->agent_order_count) }}
                            {{-- Pending work against this customer is the one thing here that
                                 needs acting on, so it is called out rather than counted in. --}}
                            @if((int) $customer->agent_pending_count > 0)
                                <br><span class="agent-badge pending">{{ number_format((int) $customer->agent_pending_count) }} pending</span>
                            @endif
                        </td>
                        <td>
                            @if($customer->agent_last_order_at)
                                {{ \Illuminate\Support\Carbon::parse($customer->agent_last_order_at)->format('M j, Y H:i') }}
                                <br><small>{{ \Illuminate\Support\Carbon::parse($customer->agent_last_order_at)->diffForHumans() }}</small>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="agent-empty">
                                @if($search !== '')
                                    No customer matches “{{ $search }}”.
                                @else
                                    No customers yet. A player appears here once you have processed a deposit or a withdrawal for them.
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="agent-pagination">{{ $customers->links() }}</div>
</section>
@endsection
