<section class="static">
    @if($heading)
        <h1 class="static__heading">{{ $heading }}</h1>
    @endif
    <div class="static__content">
        {!! $content !!}
    </div>
</section>
