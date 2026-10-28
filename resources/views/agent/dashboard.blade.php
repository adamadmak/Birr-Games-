@extends('layouts.agent')
@section('title', 'Statistics')
@section('content')
@php
    $delta = function (string $key) use ($deltas) {
        if (! array_key_exists($key, $deltas) || $deltas[$key] === null) {
            return '';
        }
        $value = $deltas[$key];
        $direction = $value > 0 ? 'up' : ($value < 0 ? 'down' : 'flat');
        $sign = $value > 0 ? '+' : '';
        return '<span class="agent-delta '.$direction.'">'.$sign.number_format($value, 1).'%</span>';
    };
@endphp

<div class="agent-page-head"><div><p>Overview</p><h1>Statistics</h1><span>{{ $periodLabel }} &middot; your Birr operations.</span></div><div class="agent-head-actions"><a class="agent-button teal" href="{{ route('agent.withdrawals') }}">Process withdrawals</a></div></div>
@if($floatWarning > 0 && (float) $agent->balance <= $floatWarning)
    {{-- Not waiting for zero: below the minimum, deposits cannot be approved until the float is topped up. --}}
    <div class="agent-alert error">
        <strong>Your float is low: {{ \App\Support\Money::exact((float) $agent->balance) }}.</strong>
        @if($floatMinimum > 0 && (float) $agent->balance <= $floatMinimum)
            That is at or below the minimum of {{ \App\Support\Money::exact($floatMinimum) }}, so new deposits cannot be approved until the administrator tops up your float.
        @else
            Deposits that would take it below {{ \App\Support\Money::exact($floatMinimum) }} will be refused. Ask the administrator to top up your float now.
        @endif
    </div>
@endif

{{-- Every figure below used to be lifetime-only, which stops telling an agent
     anything after the first month. --}}
<nav class="agent-period-tabs" aria-label="Reporting period">
    @foreach (['today' => 'Today', '7days' => 'Last 7 days', '30days' => 'Last 30 days', 'all' => 'All time'] as $key => $label)
        <a class="{{ $period === $key ? 'active' : '' }}"
           href="{{ route('agent.dashboard', ['period' => $key]) }}"
           @if($period === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

<h2 class="agent-section-title">Overview</h2>
<div class="agent-stat-grid one"><article class="agent-stat blue"><span class="agent-stat-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/><circle cx="17.5" cy="14.5" r="1.3"/></svg></span><p>Credit Balance</p><strong>{{ \App\Support\Money::format($agent->balance, 2) }}</strong><small>Current available credit</small></article></div>

<h2 class="agent-section-title">Deposits</h2>
<div class="agent-stat-grid"><article class="agent-stat amber"><span class="agent-stat-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></span><p>Pending Deposit Orders</p><strong>{{ number_format($pendingDeposits) }}</strong><small>Awaiting processing &middot; all time</small></article><article class="agent-stat green"><span class="agent-stat-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg></span><p>Completed Deposit Orders</p><strong>{{ number_format($completedDeposits) }}{!! $delta('completedDeposits') !!}</strong><small>Successfully processed &middot; {{ $periodLabel }}</small></article><article class="agent-stat pink"><span class="agent-stat-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7"/><path d="M9 7h8v8"/></svg></span><p>Total Deposited</p><strong>{{ \App\Support\Money::format($totalDeposited, 2) }}{!! $delta('totalDeposited') !!}</strong><small>Deposit volume &middot; {{ $periodLabel }}</small></article></div>

<h2 class="agent-section-title">Withdrawals</h2>
<div class="agent-stat-grid"><article class="agent-stat amber"><span class="agent-stat-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></span><p>Pending Withdrawal Orders</p><strong>{{ number_format($pendingWithdrawals) }}</strong><small>Awaiting processing &middot; all time</small></article><article class="agent-stat pink"><span class="agent-stat-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7"/><path d="M9 7h8v8"/></svg></span><p>Total Withdrawn</p><strong>{{ \App\Support\Money::format($totalWithdrawn, 2) }}{!! $delta('totalWithdrawn') !!}</strong><small>Withdrawal volume &middot; {{ $periodLabel }}</small></article></div>

<h2 class="agent-section-title">Service rating</h2>
<div class="agent-stat-grid one"><article class="agent-stat teal"><span class="agent-stat-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 2.6 5.6 6.4.8-4.7 4.3 1.3 6.3L12 17l-5.6 3 1.3-6.3L3 9.4l6.4-.8L12 3z"/></svg></span><p>Average rating</p><strong>@if($ratingCount > 0){{ number_format($ratingAverage, 1) }} / 5@else&mdash;@endif</strong><small>@if($ratingCount > 0){{ number_format($ratingCount) }} {{ \Illuminate\Support\Str::plural('rating', $ratingCount) }} from customers@else No ratings yet &middot; customers rate you after a completed order @endif</small></article></div>

<h2 class="agent-section-title">Commission</h2>
<div class="agent-stat-grid"><article class="agent-stat green"><span class="agent-stat-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg></span><p>Deposit Commission</p><strong>{{ \App\Support\Money::format($depositCommission, 2) }}{!! $delta('depositCommission') !!}</strong><small>From deposits &middot; {{ $periodLabel }}</small></article><article class="agent-stat blue"><span class="agent-stat-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg></span><p>Withdrawal Commission</p><strong>{{ \App\Support\Money::format($withdrawalCommission, 2) }}{!! $delta('withdrawalCommission') !!}</strong><small>From withdrawals &middot; {{ $periodLabel }}</small></article><article class="agent-stat teal"><span class="agent-stat-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 5H6l6.5 7L6 19h12"/></svg></span><p>Total Commission</p><strong>{{ \App\Support\Money::format($depositCommission + $withdrawalCommission, 2) }}</strong><small>Total earned &middot; {{ $periodLabel }}</small></article></div>

<h2 class="agent-section-title">Telegram Notifications</h2>
@include('agent._telegram-link')
@endsection
