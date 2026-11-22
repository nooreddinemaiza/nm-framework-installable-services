<article class="article">
    <header class="article__header">
        <h1 class="article__title">{{ $headline ?? $pageTitle ?? '' }}</h1>
        @if($author || $date)
            <p class="article__meta">
                @if($author)
                    <span class="article__author">{{ $author }}</span>
                @endif
                @if($date)
                    <time class="article__date">{{ $date }}</time>
                @endif
            </p>
        @endif
    </header>

    <div class="article__body">
        {!! $body !!}
    </div>

    @if(count($tags) > 0)
        <footer class="article__tags">
            @foreach($tags as $tag)
                <span class="tag">{{ $tag }}</span>
            @endforeach
        </footer>
    @endif
</article>
