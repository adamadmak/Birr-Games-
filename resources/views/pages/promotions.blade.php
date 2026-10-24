@extends('layouts.app')
@section('title', 'Promotions — Vamoseone')
@section('content')
<div class="content-page">
    <div class="page-intro"><p class="eyebrow">Player rewards</p><h1>More ways to explore.</h1><p>Local rewards designed to showcase the complete player journey.</p></div>
    <div class="promo-grid">
        @forelse($promotions as $promotion)
            <article class="promo-card promo-{{ $promotion->accent }}"><span>{{ $promotion->code }}</span><h2>{{ $promotion->title }}</h2><p>{{ $promotion->description }}</p><a href="{{ route('home') }}">Explore offer →</a></article>
        @empty
            <p>No active promotions right now.</p>
        @endforelse
    </div>
</div>
@endsection
