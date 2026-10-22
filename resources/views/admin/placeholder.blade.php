@extends('layouts.admin')
@section('title', $title)
@section('content')
<div class="admin-page-heading"><div><p>Module roadmap</p><h1>{{ $title }}</h1><span>This operational area is ready for product-specific requirements.</span></div></div>
<section class="admin-panel placeholder-panel"><span>◫</span><h2>{{ $title }} is scaffolded</h2><p>The navigation and permissions are in place. This module is intentionally inactive until its real business rules and data source are defined.</p><a href="{{ route('admin.dashboard') }}">Return to dashboard</a></section>
@endsection
