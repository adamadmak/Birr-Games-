{{-- "View all" and the two scroll arrows for a game rail. The arrows are driven
     by public/js/lobby-rails.js through delegated clicks, so they keep working
     after the lobby swaps its results in place while searching. --}}
<div class="rail-tools">
    @if (! empty($viewAll))
        <a class="rail-viewall" href="{{ $viewAll }}">{{ __('View All') }}</a>
    @endif
    <button type="button" class="rail-arrow" data-rail-prev aria-label="{{ __('Scroll left') }}">&#8249;</button>
    <button type="button" class="rail-arrow" data-rail-next aria-label="{{ __('Scroll right') }}">&#8250;</button>
</div>
