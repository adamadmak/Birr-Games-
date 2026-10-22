{{-- The row of studio pills under the search bar. Each is a plain link to the
     lobby's own server-side studio filter (?provider=…), so it adds no new
     behaviour: it is the dropdown above it, one tap away. --}}
@php
    $chipProviders = collect($providers ?? [])->sortByDesc('games_count')->take(9)->values();
@endphp
@if ($chipProviders->isNotEmpty())
    <nav class="lobby-chips" aria-label="{{ __('Browse by studio') }}">
        <a class="lobby-chip lobby-chip-all {{ ($catalogProvider ?? '') === '' ? 'active' : '' }}" href="{{ route('home') }}">{{ __('All') }}</a>
        @foreach ($chipProviders as $chip)
            <a class="lobby-chip lobby-chip-{{ ($loop->index % 8) + 1 }} {{ ($catalogProvider ?? '') === $chip->name ? 'active' : '' }}" href="{{ route('home', ['provider' => $chip->name]) }}">
                <span>{{ $chip->name }}</span><small>{{ number_format($chip->games_count) }}</small>
            </a>
        @endforeach
    </nav>
@endif
