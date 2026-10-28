@extends('layouts.app')
@section('title', __('Promotions') . ' — Vamoseone')
@section('content')
<div class="content-page">
    <div class="page-intro"><p class="eyebrow">{{ __('Player rewards') }}</p><h1>{{ __('Bonuses and rewards.') }}</h1><p>{{ __('Deposit bonuses, daily tournaments and free prediction pools for Vamoseone players.') }}</p></div>
    <div class="promo-grid">
        @forelse($promotions as $promotion)
            <article class="promo-card promo-{{ $promotion->accent }}"><span>{{ $promotion->code }}</span><h2>{{ $promotion->title }}</h2><p>{{ $promotion->description }}</p><a href="{{ route('home') }}">Explore offer →</a></article>
        @empty
            <p>{{ __('No active promotions right now.') }}</p>
        @endforelse
    </div>
</div>
@endsection
