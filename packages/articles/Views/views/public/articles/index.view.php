@extends('layouts.app')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$listing = $article_listing ?? ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'categories' => []];
$articles = $listing['items'] ?? [];
$categories = $listing['categories'] ?? [];
$popular = $article_popular ?? [];
$categoryId = (int) ($article_category_id ?? 0);
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$link = 'inline-flex min-h-11 items-center rounded-md bg-white px-5 font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59] ' . $focus;
$pageUrl = static function (int $page) use ($categoryId): string {
    $query = ['page' => $page];
    if ($categoryId > 0) $query['category_id'] = $categoryId;
    return '/articles?' . http_build_query($query);
};
?>
@title('Articles | ' . app()->config('app.name'))
@description('Lisez les articles et les actualités publiés.')
@meta('robots', 'index,follow')
@og('type', 'website')
@og('title', 'Articles')
@og('description', 'Lisez les articles et les actualités publiés.')

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
<style>
    .shelf { font-family: 'Anybody', ui-sans-serif, system-ui, sans-serif; }
    .shelf-sign { transform-origin: 50% 0; transform: rotate(-1.2deg); }
</style>
@include('partials.site-nav', ['active' => 'articles'])
<main class="shelf min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc]">
    <div class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-x-2 text-sm" aria-label="Fil d’Ariane">
            <a class="rounded underline underline-offset-4 <?= $focus ?>" href="/">Accueil</a>
            <span aria-hidden="true">/</span>
            <span class="font-bold" aria-current="page">Articles</span>
        </nav>

        <header class="mt-4 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
            <div class="shelf-sign w-fit max-w-full">
                <div class="flex justify-between px-8" aria-hidden="true">
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                </div>
                <h1 class="break-words rounded-xl bg-[#ff7a1a] px-6 py-4 text-4xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:80%] dark:shadow-[0_6px_0_#3a5f59] sm:px-8 sm:text-7xl">Articles</h1>
            </div>
            <p class="max-w-sm text-lg leading-snug">Des idées, des conseils et des nouvelles à lire à votre rythme.</p>
        </header>

        <?php if ($categories !== []): ?>
            <nav class="mt-12 flex flex-wrap gap-3" aria-label="Filtrer par catégorie">
                <a class="relative inline-flex min-h-11 items-center rounded-md py-2 pl-10 pr-5 font-bold shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:shadow-[0_3px_0_#3a5f59] before:absolute before:left-3.5 before:top-1/2 before:h-3 before:w-3 before:-translate-y-1/2 before:rounded-full before:bg-[#cfe8dc] before:ring-2 before:ring-[#10302c] dark:before:bg-[#0c1c1a] <?= $categoryId === 0 ? 'bg-[#ff7a1a] text-[#10302c]' : 'bg-white text-[#10302c] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]' ?> <?= $focus ?>" href="/articles" <?= $categoryId === 0 ? 'aria-current="true"' : '' ?>>Tous les articles</a>
                <?php foreach ($categories as $category): ?>
                    <?php $selected = $categoryId === (int) $category->getKey(); ?>
                    <a class="relative inline-flex min-h-11 items-center rounded-md py-2 pl-10 pr-5 font-bold shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:shadow-[0_3px_0_#3a5f59] before:absolute before:left-3.5 before:top-1/2 before:h-3 before:w-3 before:-translate-y-1/2 before:rounded-full before:bg-[#cfe8dc] before:ring-2 before:ring-[#10302c] dark:before:bg-[#0c1c1a] <?= $selected ? 'bg-[#ff7a1a] text-[#10302c]' : 'bg-white text-[#10302c] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]' ?> <?= $focus ?>" href="/articles?category_id=<?= (int) $category->getKey() ?>" <?= $selected ? 'aria-current="true"' : '' ?>><?= $escape($category->getAttribute('name')) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php if ($popular !== [] && $categoryId === 0): ?>
            <section class="mt-14" aria-labelledby="popular-articles-heading">
                <h2 id="popular-articles-heading" class="mb-5 text-3xl font-extrabold [font-stretch:80%] sm:text-4xl">Les plus lus</h2>
                <div class="overflow-x-auto pb-3 [scrollbar-width:thin]" role="region" tabindex="0" aria-label="Articles les plus lus">
                    <div class="w-max min-w-full border-b-[14px] border-[#10302c] dark:border-[#3a5f59]">
                        <ul class="flex items-end gap-3">
                            <?php foreach ($popular as $article): ?>
                                <li class="w-52 shrink-0 sm:w-60">
                                    <a class="flex min-h-44 flex-col justify-between rounded-t-md bg-[#10302c] p-4 text-[#cfe8dc] transition hover:-translate-y-1 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] <?= $focus ?>" href="/articles/<?= rawurlencode((string) $article['slug']) ?>">
                                        <span class="text-sm font-bold">Article populaire</span>
                                        <span class="mt-6 block break-words text-2xl font-extrabold leading-none [font-stretch:70%]"><?= $escape($article['title']) ?></span>
                                        <span class="mt-3 text-xs font-bold tabular-nums"><?= number_format((int) $article['views'], 0, ',', ' ') ?> lectures</span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="mt-16" aria-labelledby="all-articles-heading">
            <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="all-articles-heading" class="text-3xl font-extrabold [font-stretch:80%] sm:text-4xl"><?= $categoryId > 0 ? 'Articles de la catégorie' : 'À lire' ?></h2>
                <p class="text-sm"><span class="font-extrabold tabular-nums"><?= (int) $listing['total'] ?></span> article(s)</p>
            </div>
            <?php if ($articles === []): ?>
                <div class="rounded-md border-4 border-dashed border-[#10302c] px-6 py-16 text-center dark:border-[#3a5f59]">
                    <p class="text-3xl font-extrabold [font-stretch:80%]">La prochaine lecture vous attend</p>
                    <p class="mx-auto mt-2 max-w-sm">Aucun article publié ne correspond à cette catégorie pour le moment.</p>
                    <?php if ($categoryId > 0): ?><a class="mt-6 <?= $link ?>" href="/articles">Voir tous les articles</a><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="space-y-10">
                    <?php foreach (array_chunk($articles, 5) as $shelfIndex => $shelf): ?>
                        <div class="-mx-4 overflow-x-auto pb-3 [scrollbar-width:thin] sm:mx-0" role="region" tabindex="0" aria-label="Articles, rangée <?= $shelfIndex + 1 ?>">
                            <div class="w-max min-w-full border-b-[14px] border-[#10302c] px-4 dark:border-[#3a5f59] sm:px-0">
                                <ul class="flex items-end gap-3">
                                    <?php foreach ($shelf as $article): ?>
                                        <?php
                                        $image = trim((string) $article->getAttribute('featured_image', ''));
                                        $date = (string) ($article->getAttribute('published_at') ?: $article->getAttribute('created_at'));
                                        ?>
                                        <li class="w-64 shrink-0 sm:w-72">
                                            <article class="relative flex min-h-80 flex-col overflow-hidden rounded-t-md bg-white shadow-[0_4px_0_#10302c] transition hover:-translate-y-1 dark:bg-[#143632] dark:shadow-[0_4px_0_#3a5f59]">
                                                <?php if ($image !== ''): ?>
                                                    <img src="<?= $escape($image) ?>" alt="<?= $escape($article->getAttribute('title')) ?>" loading="lazy" class="h-36 w-full border-b-[8px] border-[#10302c] bg-[#e4f3eb] object-cover dark:border-[#3a5f59] dark:bg-[#0c1c1a]">
                                                <?php else: ?>
                                                    <div class="flex h-36 items-end border-b-[8px] border-[#10302c] bg-[#e4f3eb] p-4 dark:border-[#3a5f59] dark:bg-[#0c1c1a]" aria-hidden="true">
                                                        <span class="text-5xl font-extrabold leading-none text-[#10302c]/20 dark:text-[#cfe8dc]/20">Aa</span>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="flex flex-1 flex-col p-4">
                                                    <p class="text-xs font-bold"><?= $escape($date) ?></p>
                                                    <h3 class="mt-3 break-words text-xl font-extrabold leading-[0.95] [font-stretch:75%] line-clamp-2">
                                                        <a class="after:absolute after:inset-0 <?= $focus ?>" href="/articles/<?= rawurlencode((string) $article->getAttribute('slug')) ?>"><?= $escape($article->getAttribute('title')) ?></a>
                                                    </h3>
                                                    <?php if (($article->getAttribute('excerpt') ?? '') !== ''): ?><p class="mt-3 line-clamp-3 text-sm leading-6"><?= $escape($article->getAttribute('excerpt')) ?></p><?php endif; ?>
                                                    <?php if ($article->getAttribute('categories', []) !== []): ?>
                                                        <p class="mt-auto pt-4 text-xs font-bold"><?= $escape(implode(' · ', $article->getAttribute('categories'))) ?></p>
                                                    <?php endif; ?>
                                                    <a class="relative z-10 mt-4 inline-flex min-h-11 items-center justify-center rounded-md bg-[#ff7a1a] px-4 text-sm font-bold text-[#10302c] shadow-[0_3px_0_#10302c] dark:shadow-[0_3px_0_#3a5f59] <?= $focus ?>" href="/articles/<?= rawurlencode((string) $article->getAttribute('slug')) ?>">Lire l’article</a>
                                                </div>
                                            </article>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ((int) $listing['pages'] > 1): ?>
                    <nav class="mt-14 flex items-center justify-between gap-4" aria-label="Pagination des articles">
                        <?php if ((int) $listing['page'] > 1): ?><a class="<?= $link ?>" href="<?= $escape($pageUrl((int) $listing['page'] - 1)) ?>" rel="prev">Précédent</a><?php else: ?><span></span><?php endif; ?>
                        <span class="text-sm">Page <span class="font-extrabold tabular-nums"><?= (int) $listing['page'] ?></span> sur <?= (int) $listing['pages'] ?></span>
                        <?php if ((int) $listing['page'] < (int) $listing['pages']): ?><a class="<?= $link ?>" href="<?= $escape($pageUrl((int) $listing['page'] + 1)) ?>" rel="next">Suivant</a><?php else: ?><span></span><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</main>
@endsection
