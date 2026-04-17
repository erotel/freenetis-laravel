@if ($paginator->hasPages())
<nav class="fn-pagination" aria-label="Stránkování">
    {{-- Předchozí --}}
    @if ($paginator->onFirstPage())
        <span class="fn-page fn-page-disabled">«</span>
    @else
        <a class="fn-page" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Předchozí">«</a>
    @endif

    @foreach ($elements as $element)
        @if (is_string($element))
            <span class="fn-page fn-page-disabled">{{ $element }}</span>
        @endif
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span class="fn-page fn-page-active" aria-current="page">{{ $page }}</span>
                @else
                    <a class="fn-page" href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach

    {{-- Další --}}
    @if ($paginator->hasMorePages())
        <a class="fn-page" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Další">»</a>
    @else
        <span class="fn-page fn-page-disabled">»</span>
    @endif
</nav>
@endif
