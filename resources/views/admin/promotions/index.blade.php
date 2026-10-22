@extends('layouts.admin')
@section('title', 'Promotions')
@section('content')
<div class="admin-page-heading"><div><p>Growth</p><h1>Promotions</h1><span>Create and publish offers shown on the player promotions page.</span></div></div>
<div class="management-grid">
    <section class="admin-panel promotion-list">
        <div class="panel-title"><div><p>Published content</p><h2>Promotion library</h2></div><span>{{ $promotions->count() }} total</span></div>
        @foreach($promotions as $promotion)
            <article class="promotion-row"><span class="promotion-accent accent-{{ $promotion->accent }}"></span><div><small>{{ $promotion->code }}</small><h3>{{ $promotion->title }}</h3><p>{{ $promotion->description }}</p></div><span class="status-badge {{ $promotion->active ? 'won' : '' }}">{{ $promotion->active ? 'Active' : 'Paused' }}</span><div class="promotion-actions"><form method="POST" action="{{ route('admin.promotions.toggle',$promotion) }}">@csrf @method('PATCH')<button>{{ $promotion->active ? 'Pause' : 'Publish' }}</button></form><form method="POST" action="{{ route('admin.promotions.destroy',$promotion) }}">@csrf @method('DELETE')<button class="danger">Delete</button></form></div></article>
        @endforeach
    </section>
    <section class="admin-panel create-panel">
        <div class="panel-title"><div><p>New offer</p><h2>Create promotion</h2></div></div>
        <form class="admin-form" method="POST" action="{{ route('admin.promotions.store') }}">@csrf
            <label>Title<input name="title" value="{{ old('title') }}" required></label>
            <label>Description<textarea name="description" rows="4" required>{{ old('description') }}</textarea></label>
            <div><label>Code<input name="code" value="{{ old('code') }}" required></label><label>Accent<select name="accent"><option value="cyan">Cyan</option><option value="gold">Gold</option><option value="violet">Violet</option></select></label></div>
            <div><label>Starts<input type="date" name="starts_at"></label><label>Ends<input type="date" name="ends_at"></label></div>
            <button type="submit">Create and publish</button>
        </form>
    </section>
</div>
@endsection
