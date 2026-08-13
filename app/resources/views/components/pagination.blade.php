@if ($paginator->hasPages())
<nav class="compact-pagination" role="navigation" aria-label="Paginação">
    @if ($paginator->onFirstPage())
        <span class="page-control disabled" aria-disabled="true" aria-label="Página anterior">‹</span>
    @else
        <a class="page-control" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Página anterior">‹</a>
    @endif
    @foreach ($elements as $element)
        @if (is_string($element))<span class="page-ellipsis">{{ $element }}</span>@endif
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span class="page-number active" aria-current="page">{{ $page }}</span>
                @else
                    <a class="page-number" href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach
    @if ($paginator->hasMorePages())
        <a class="page-control" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Próxima página">›</a>
    @else
        <span class="page-control disabled" aria-disabled="true" aria-label="Próxima página">›</span>
    @endif
</nav>
@endif
