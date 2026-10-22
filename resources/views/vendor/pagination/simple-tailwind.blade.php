@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="simple-pagination">
        @if ($paginator->onFirstPage())
            <span class="pagination-btn disabled" aria-disabled="true">
                <svg class="pg-ico" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg> Previous
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="pagination-btn" rel="prev">
                <svg class="pg-ico" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg> Previous
            </a>
        @endif

        <span class="pagination-info">
            <strong>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</strong>
            @if(method_exists($paginator, 'total') && $paginator->total())
                <small class="pagination-total">({{ number_format($paginator->total()) }} total)</small>
            @endif
        </span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="pagination-btn" rel="next">
                Next <svg class="pg-ico" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        @else
            <span class="pagination-btn disabled" aria-disabled="true">
                Next <svg class="pg-ico" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </span>
        @endif
    </nav>
@endif
