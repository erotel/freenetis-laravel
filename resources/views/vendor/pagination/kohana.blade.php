@if ($paginator->hasPages())
<div style="margin:8px 0;">
    «
    @if ($paginator->onFirstPage())
        <span style="color:#999;">předchozí</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}">předchozí</a>
    @endif

    @foreach ($elements as $element)
        @if (is_string($element))
            <span style="color:#999;">{{ $element }}</span>
        @endif
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span style="border:1px solid #c00; padding:1px 5px; font-weight:bold;">{{ $page }}</span>
                @else
                    <a href="{{ $url }}" style="padding:1px 5px;">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach

    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}">další</a>
    @else
        <span style="color:#999;">další</span>
    @endif
    »
</div>
@endif
