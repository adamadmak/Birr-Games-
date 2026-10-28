@extends('layouts.agent')
@section('title', 'Counter')
@section('content')
<div class="agent-page-head">
    <div><p>Walk-in customers</p><h1>Counter</h1><span>Bet for customers without an account: they pay you cash, you print the ticket.</span></div>
</div>

<section class="agent-panel">
    <div class="agent-panel-head"><div><h2>How it works</h2><p>Your counter is a betting account only you can open, funded from your float.</p></div></div>
    <ol class="agent-counter-steps">
        <li>Move float to the counter below (once, or whenever it runs low).</li>
        <li>Take the customer's cash and open the sportsbook as the counter. Place exactly the bet they ask for.</li>
        <li>Print the ticket from Bet Tickets and hand it over.</li>
        <li>When a customer returns with a winning ticket, press Pay out on Bet Tickets and pay them the amount shown. Each ticket can be paid once.</li>
        <li>Move the counter balance back to your float whenever you like.</li>
    </ol>
</section>

<div class="agent-counter-grid">
    <section class="agent-panel">
        <div class="agent-panel-head"><div><h2>Balances</h2></div></div>
        <div class="agent-counter-balances">
            <div><small>Counter balance</small><strong>{{ \App\Support\Money::exact((float) $counter->credits) }}</strong></div>
            <div><small>Your float</small><strong>{{ \App\Support\Money::exact((float) $agent->balance) }}</strong>@if($floatMinimum > 0)<span>Minimum {{ \App\Support\Money::exact($floatMinimum) }}</span>@endif</div>
        </div>
        <form method="POST" action="{{ route('agent.counter.open') }}" target="_blank">
            @csrf
            <button class="agent-button teal" type="submit">Open the sportsbook as the counter</button>
        </form>
    </section>

    <section class="agent-panel">
        <div class="agent-panel-head"><div><h2>Move money</h2></div></div>
        <form class="agent-form" method="POST" action="{{ route('agent.counter.top-up') }}">
            @csrf
            <label>Float to counter<input type="number" name="amount" min="1" step="0.01" required placeholder="ETB"></label>
            <button class="agent-button teal" type="submit">Top up counter</button>
        </form>
        <form class="agent-form" method="POST" action="{{ route('agent.counter.give-back') }}">
            @csrf
            <label>Counter to float<input type="number" name="amount" min="0.01" step="0.01" max="{{ (float) $counter->credits }}" required placeholder="ETB"></label>
            <button class="agent-button" type="submit">Return to float</button>
        </form>
    </section>
</div>
@endsection
