@extends('layouts.app')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$title = (string) $article->getAttribute('title', '');
$plainContent = trim(strip_tags((string) $article->getAttribute('content', '')));
$plainContent = preg_match('/^(.{0,155})/us', $plainContent, $descriptionMatch) === 1
    ? $descriptionMatch[1]
    : $plainContent;
$description = trim((string) $article->getAttribute('excerpt', '')) ?: $plainContent;
$slug = (string) $article->getAttribute('slug', '');
$image = trim((string) $article->getAttribute('featured_image', ''));
$categories = $article->getAttribute('categories', []);
$content = (string) $article->getAttribute('content', '');
$relatedArticles = is_array($article_related ?? null) ? $article_related : [];
$publishedAt = (string) ($article->getAttribute('published_at') ?: $article->getAttribute('created_at'));
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$focusCard = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-[#143632]';
$canonicalBase = rtrim((string) app()->environment()->get('APP_URL', ''), '/');
$canonical = $canonicalBase . '/articles/' . rawurlencode($slug);
$canonical = filter_var($canonical, FILTER_VALIDATE_URL) ? $canonical : '';
$hasSidebar = $relatedArticles !== [];
?>
@title($title . ' | ' . app()->config('app.name'))
@description($description)
@if($canonical !== '')
@canonical($canonical)
@endif
@meta('robots', 'index,follow')
@og('type', 'article')
@og('title', $title)
@og('description', $description)
@if($canonical !== '')
@og('url', $canonical)
@endif
@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
<style>
    .shelf { font-family: 'Anybody', ui-sans-serif, system-ui, sans-serif; }
    .shelf-sign { transform-origin: 50% 0; transform: rotate(-1.2deg); }
    .article-body { overflow-wrap: anywhere; }
    .article-body > :first-child { margin-top: 0; }
    .article-body > * + * { margin-top: 1.15em; }
    .article-body h1, .article-body h2, .article-body h3,
    .article-body h4, .article-body h5, .article-body h6 { font-weight: 900; line-height: 1.15; }
    .article-body h1 { font-size: 2rem; }
    .article-body h2 { font-size: 1.65rem; }
    .article-body h3 { font-size: 1.35rem; }
    .article-body ul, .article-body ol { padding-left: 1.6em; }
    .article-body ul { list-style: disc; }
    .article-body ol { list-style: decimal; }
    .article-body li + li { margin-top: .35em; }
    .article-body li[data-checked="true"] { list-style: "✓  "; }
    .article-body blockquote { border-left: 5px solid #ff7a1a; padding-left: 1em; font-weight: 700; }
    .article-body a { color: #a63d00; text-decoration: underline; text-underline-offset: .2em; }
    .article-body pre { overflow-x: auto; border-radius: .4rem; background: #10302c; padding: 1rem; color: #cfe8dc; }
    .article-body code { border-radius: .2rem; background: #10302c18; padding: .1em .3em; }
    .article-body pre code { background: transparent; padding: 0; }
    .article-body table { display: block; max-width: 100%; overflow-x: auto; border-collapse: collapse; }
    .article-body th, .article-body td { border: 1px solid currentColor; padding: .45rem .7rem; text-align: left; }
    .article-body th { font-weight: 800; }
    .article-body img { max-width: 100%; height: auto; }
    .article-body figure { max-width: 100%; }
    .article-body hr { border: 0; border-top: 3px solid currentColor; }
    .dark .article-body a { color: #ff9a4f; }
</style>
@include('partials.site-nav', ['active' => 'articles'])
<main class="shelf min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc]">
    <div class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-x-2 text-sm" aria-label="Fil d’Ariane">
            <a class="rounded underline underline-offset-4 <?= $focus ?>" href="/">Accueil</a>
            <span aria-hidden="true">/</span>
            <a class="rounded underline underline-offset-4 <?= $focus ?>" href="/articles">Articles</a>
            <span aria-hidden="true">/</span>
            <span class="max-w-full break-words font-bold" aria-current="page"><?= $escape($title) ?></span>
        </nav>

        <?php if (!empty($article_preview)): ?>
            <p role="status" class="mt-6 rounded-md border-2 border-dashed border-[#10302c] px-4 py-3 text-sm font-bold dark:border-[#3a5f59]">Prévisualisation privée — cet article n’est pas publié.</p>
        <?php endif; ?>

        <div class="mt-4 grid gap-12 <?= $hasSidebar ? 'lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-10' : '' ?>">
            <article class="min-w-0 <?= $hasSidebar ? '' : 'mx-auto w-full max-w-5xl' ?>">
                <header>
                    <div class="shelf-sign w-fit max-w-full">
                        <div class="flex justify-between px-8" aria-hidden="true">
                            <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                            <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                        </div>
                        <h1 class="break-words rounded-xl bg-[#ff7a1a] px-6 py-4 text-4xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:75%] dark:shadow-[0_6px_0_#3a5f59] sm:px-8 sm:text-6xl"><?= $escape($title) ?></h1>
                    </div>
                    <div class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-2">
                        <time class="rounded-md bg-[#10302c] px-3 py-1 text-sm font-bold text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]" datetime="<?= $escape($publishedAt) ?>"><?= $escape($publishedAt) ?></time>
                        <?php if (($article->getAttribute('author_name') ?? '') !== ''): ?><span class="text-sm font-bold">Par <?= $escape($article->getAttribute('author_name')) ?></span><?php endif; ?>
                        <?php foreach ($categories as $category): ?>
                            <span class="text-sm font-bold"><?= $escape($category) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($description !== ''): ?><p class="mt-5 max-w-3xl text-xl leading-8"><?= $escape($description) ?></p><?php endif; ?>
                </header>

                <?php if ($image !== ''): ?>
                    <figure class="mt-10 overflow-hidden rounded-t-xl border-b-[14px] border-[#10302c] bg-[#e4f3eb] dark:border-[#3a5f59] dark:bg-[#143632]">
                        <img src="<?= $escape($image) ?>" alt="<?= $escape($title) ?>" class="max-h-[34rem] w-full object-cover" fetchpriority="high">
                    </figure>
                <?php endif; ?>

                <div class="mt-10 rounded-md bg-white p-6 text-base leading-7 shadow-[0_6px_0_#10302c] dark:bg-[#143632] dark:shadow-[0_6px_0_#3a5f59] sm:p-10">
                    <div class="article-body break-words"><?= $content ?></div>
                </div>
            </article>

            <?php if ($hasSidebar): ?>
                <aside class="min-w-0 border-t-[14px] border-[#10302c] pt-8 dark:border-[#3a5f59] lg:sticky lg:top-6 lg:max-h-[calc(100dvh-3rem)] lg:overflow-y-auto lg:border-t-0 lg:p-1 lg:pt-1 [scrollbar-width:thin]" aria-labelledby="related-articles-heading">
                    <h2 id="related-articles-heading" class="text-3xl font-extrabold [font-stretch:80%]">À lire aussi</h2>
                    <ul class="mt-5 space-y-4">
                        <?php foreach ($relatedArticles as $related): ?>
                            <?php
                            $relatedImage = trim((string) ($related['featured_image'] ?? ''));
                            $relatedSlug = (string) ($related['slug'] ?? '');
                            $relatedTitle = (string) ($related['title'] ?? '');
                            ?>
                            <li>
                                <article class="relative flex overflow-hidden rounded-md bg-white shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#143632] dark:shadow-[0_4px_0_#3a5f59]">
                                    <?php if ($relatedImage !== ''): ?>
                                        <img src="<?= $escape($relatedImage) ?>" alt="" loading="lazy"
                                            class="w-24 shrink-0 self-stretch border-r-[8px] border-[#10302c] bg-[#e4f3eb] object-cover dark:border-[#3a5f59] dark:bg-[#0c1c1a]">
                                    <?php else: ?>
                                        <div class="flex w-24 shrink-0 items-end self-stretch border-r-[8px] border-[#10302c] bg-[#e4f3eb] p-3 dark:border-[#3a5f59] dark:bg-[#0c1c1a]" aria-hidden="true">
                                            <span class="text-4xl font-extrabold leading-none text-[#10302c]/20 dark:text-[#cfe8dc]/20">Aa</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex min-w-0 flex-1 flex-col p-3">
                                        <p class="text-xs font-bold"><?= $escape($related['published_at'] ?? '') ?></p>
                                        <h3 class="mt-2 break-words text-lg font-extrabold leading-[0.95] [font-stretch:75%] line-clamp-3">
                                            <a class="after:absolute after:inset-0 <?= $focusCard ?>"
                                                href="/articles/<?= rawurlencode($relatedSlug) ?>"><?= $escape($relatedTitle) ?></a>
                                        </h3>
                                        <?php if (($related['categories'] ?? []) !== []): ?>
                                            <p class="mt-auto break-words pt-3 text-xs font-bold"><?= $escape(implode(' · ', $related['categories'])) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="mt-6 inline-flex min-h-11 items-center rounded font-bold underline underline-offset-4 <?= $focus ?>" href="/articles">Tous les articles</a>
                </aside>
            <?php endif; ?>
        </div>

        <footer class="mt-12 flex flex-wrap items-center justify-between gap-4">
            <a class="inline-flex min-h-11 items-center rounded-md bg-white px-5 font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59] <?= $focus ?>" href="/articles">← Tous les articles</a>
            <?php if (!empty($article_preview)): ?><a class="rounded font-bold underline underline-offset-4 <?= $focus ?>" href="/dashboard/articles">Retour aux articles</a><?php endif; ?>
        </footer>
    </div>
</main>
@endsection