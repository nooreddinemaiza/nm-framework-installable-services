<section class="hero">
    <h1 class="hero__title">{{ $headline }}</h1>
    @if($subtitle !== '')
        <p class="hero__subtitle">{{ $subtitle }}</p>
    @endif
    @if($cta)
        <a class="button button--primary" href="{{ $cta['url'] ?? '#' }}">{{ $cta['label'] ?? 'Commencer' }}</a>
    @endif
</section>

@if(count($features) > 0)
    <section class="features">
        @foreach($features as $feature)
            <div class="feature {{ $loop->odd() ? 'feature--odd' : 'feature--even' }}">
                <h2 class="feature__title">{{ $feature['title'] ?? '' }}</h2>
                <p class="feature__text">{{ $feature['text'] ?? '' }}</p>
            </div>
        @endforeach
    </section>
@endif
