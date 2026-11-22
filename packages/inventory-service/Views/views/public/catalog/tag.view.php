@extends('layouts.app')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$content = is_array($tagged_content ?? null) ? $tagged_content : [];
$products = is_array($content['products'] ?? null) ? $content['products'] : [];
$categories = is_array($content['categories'] ?? null) ? $content['categories'] : [];
$brands = is_array($content['brands'] ?? null) ? $content['brands'] : [];
$total = count($products) + count($categories) + count($brands);
$baseUrl = rtrim((string) app()->environment()->get('APP_URL', ''), '/');
$canonical = $baseUrl . '/tags/' . rawurlencode(\App\Catalog\SeoTags::slug((string) ($content['tag'] ?? '')));
$canonical = filter_var($canonical, FILTER_VALIDATE_URL) ? $canonical : '';
$description = 'Découvrez les produits, services, catégories et marques associés au tag « ' . (string) ($content['tag'] ?? '') . ' ».';
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
?>
@title(($content['tag'] ?? 'Tag') . ' | ' . app()->config('app.name'))
@description($description)
@meta('robots', 'index,follow')
@og('type', 'website')
@og('title', (string) ($content['tag'] ?? 'Tag'))
@og('description', $description)
@if($canonical !== '')
@canonical($canonical)
@og('url', $canonical)
@endif

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
@include('partials.site-nav', ['active' => ''])
<main class="min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc] [font-family:Anybody,ui-sans-serif,system-ui,sans-serif]">
    <div class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-x-2 text-sm" aria-label="Fil d’Ariane">
            <a class="rounded underline underline-offset-4 <?= $focus ?>" href="/">Accueil</a>
            <span aria-hidden="true">/</span>
            <a class="rounded underline underline-offset-4 <?= $focus ?>" href="/tags">Tags</a>
            <span aria-hidden="true">/</span>
            <span class="font-bold" aria-current="page"><?= $escape($content['tag'] ?? '') ?></span>
        </nav>

        <header class="mt-4">
            <div class="origin-top rotate-[-1.2deg] w-fit max-w-full">
                <div class="flex justify-between px-8" aria-hidden="true">
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                    <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                </div>
                <h1 class="break-words rounded-xl bg-[#ff7a1a] px-6 py-4 text-4xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:80%] dark:shadow-[0_6px_0_#3a5f59] sm:px-8 sm:text-7xl"><?= $escape($content['tag'] ?? '') ?></h1>
            </div>
            <div class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-2">
                <span class="rounded-md bg-[#10302c] px-3 py-1 text-sm font-bold text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]">Tag</span>
                <p class="text-sm"><span class="font-extrabold tabular-nums"><?= $total ?></span> contenu<?= $total > 1 ? 's' : '' ?> associé<?= $total > 1 ? 's' : '' ?></p>
            </div>
            <p class="mt-4 max-w-xl text-lg leading-snug"><?= $escape($description) ?></p>
        </header>

        <?php
        $sections = [
            ['key' => 'products', 'title' => 'Produits et services', 'items' => $products, 'path' => '/products/', 'label' => static fn(array $item): string => ($item['kind'] ?? '') === 'service' ? 'Service' : 'Produit'],
            ['key' => 'categories', 'title' => 'Catégories', 'items' => $categories, 'path' => '/categories/', 'label' => static fn(array $item): string => 'Catégorie'],
            ['key' => 'brands', 'title' => 'Marques', 'items' => $brands, 'path' => '/brands/', 'label' => static fn(array $item): string => 'Marque'],
        ];
        $bookTone = [
            'categories' => 'bg-[#ff7a1a] text-[#10302c]',
            'brands' => 'bg-[#10302c] text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]',
        ];
        ?>
        <?php foreach ($sections as $section): ?>
            <?php if ($section['items'] !== []): ?>
                <?php $shelves = array_chunk(array_values($section['items']), $section['key'] === 'products' ? 5 : 4); ?>
                <section class="mt-16" aria-labelledby="tag-<?= $escape($section['key']) ?>-title">
                    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
                        <h2 id="tag-<?= $escape($section['key']) ?>-title" class="text-3xl font-extrabold [font-stretch:80%] sm:text-4xl"><?= $escape($section['title']) ?></h2>
                        <p class="text-sm font-extrabold tabular-nums"><?= count($section['items']) ?></p>
                    </div>
                    <div class="space-y-10">
                        <?php foreach ($shelves as $shelfIndex => $shelf): ?>
                            <div class="-mx-4 overflow-x-auto pb-3 [scrollbar-width:thin] sm:mx-0" role="region" tabindex="0" aria-label="<?= $escape($section['title']) ?>, rangée <?= $shelfIndex + 1 ?>">
                                <div class="w-max min-w-full border-b-[14px] border-[#10302c] px-4 dark:border-[#3a5f59] sm:px-0">
                                    <ul class="flex items-end gap-3">
                                        <?php foreach ($shelf as $item): ?>
                                            <?php if ($section['key'] === 'products'): ?>
                                                <li class="w-44 shrink-0 sm:w-52">
                                                    @include('partials.public.product-card', ['product' => $item, 'escape' => $escape])
                                                </li>
                                            <?php else: ?>
                                                <li class="w-52 shrink-0 sm:w-60">
                                                    <a href="<?= $escape($section['path'] . rawurlencode((string) $item['slug'])) ?>"
                                                        class="flex min-h-44 flex-col justify-between rounded-t-md p-4 transition hover:-translate-y-1 <?= $bookTone[$section['key']] ?? '' ?> <?= $focus ?>">
                                                        <span class="text-sm font-bold"><?= $escape($section['label']($item)) ?></span>
                                                        <span class="mt-6 block break-words text-3xl font-extrabold leading-none [font-stretch:70%]"><?= $escape($item['name']) ?></span>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($total === 0): ?>
            <div class="mt-16 rounded-2xl border-4 border-dashed border-[#10302c] px-6 py-16 text-center dark:border-[#3a5f59]">
                <p class="text-3xl font-extrabold [font-stretch:80%]">Rien à découvrir ici pour l’instant</p>
                <p class="mx-auto mt-2 max-w-sm">Aucun contenu actif n’est associé à ce tag.</p>
                <a class="mt-6 inline-flex min-h-11 items-center rounded-md bg-[#ff7a1a] px-6 font-bold text-[#10302c] shadow-[0_4px_0_#10302c] dark:shadow-[0_4px_0_#3a5f59] <?= $focus ?>" href="/tags">Voir tous les tags</a>
            </div>
        <?php endif; ?>
    </div>
</main>
@endsection