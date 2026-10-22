<footer class="casino-pagination">
    <span>Showing {{ number_format($paginator->count()) }} of {{ number_format($paginator->total()) }} record(s).</span>
    <div>
        <form method="GET">
            @foreach(request()->except(['per_page', $pageName]) as $key => $value)@if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
            <label>Page Size <select name="per_page" onchange="this.form.submit()">@foreach([10,25,50] as $size)<option value="{{ $size }}" @selected($paginator->perPage() === $size)>{{ $size }}</option>@endforeach</select></label>
        </form>
        @if($paginator->onFirstPage())<span class="casino-page-button disabled"><i class="fa-solid fa-chevron-left"></i></span>@else<a class="casino-page-button" href="{{ $paginator->previousPageUrl() }}"><i class="fa-solid fa-chevron-left"></i></a>@endif
        <strong>Page {{ $paginator->currentPage() }} of {{ max(1,$paginator->lastPage()) }}</strong>
        @if($paginator->hasMorePages())<a class="casino-page-button" href="{{ $paginator->nextPageUrl() }}"><i class="fa-solid fa-chevron-right"></i></a>@else<span class="casino-page-button disabled"><i class="fa-solid fa-chevron-right"></i></span>@endif
    </div>
</footer>
