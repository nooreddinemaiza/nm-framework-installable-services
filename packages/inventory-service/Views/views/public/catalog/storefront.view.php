@extends('layouts.app')
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = is_array($storefront_filters ?? null) ? $storefront_filters : [];
$listing = is_array($storefront_listing ?? null) ? $storefront_listing : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$categories = is_array($storefront_categories ?? null) ? $storefront_categories : [];
$brands = is_array($storefront_brands ?? null) ? $storefront_brands : [];
$selectedCategory = is_array($storefront_selected_category ?? null) ? $storefront_selected_category : null;
$selectedBrand = is_array($storefront_selected_brand ?? null) ? $storefront_selected_brand : null;
$search = (string) ($filters['search'] ?? '');
$categoryId = (int) ($filters['category_id'] ?? 0);
$brandId = (int) ($filters['brand_id'] ?? 0);
$sort = (string) ($filters['sort'] ?? 'newest');
$total = (int) ($listing['total'] ?? 0);
$hasFilters = $search !== '' || $categoryId > 0 || $brandId > 0;
$seoBaseUrl = rtrim((string) app()->environment()->get('APP_URL', ''), '/');
$canonical = filter_var($seoBaseUrl . '/boutique', FILTER_VALIDATE_URL) ? $seoBaseUrl . '/boutique' : '';
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$focusPanel = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-[#143632]';
$focusWindow = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#10302c] dark:focus-visible:ring-offset-[#143632]';
$field = 'min-h-11 w-full rounded-md border-2 border-[#10302c] bg-white px-3 text-[#10302c] dark:bg-[#cfe8dc] ' . $focusPanel;
$windowChip = 'relative inline-flex min-h-9 items-center rounded-md bg-[#cfe8dc] py-1.5 pl-9 pr-4 text-sm font-bold text-[#10302c] shadow-[0_3px_0_#0c1c1a] transition hover:-translate-y-0.5 before:absolute before:left-3 before:top-1/2 before:h-2.5 before:w-2.5 before:-translate-y-1/2 before:rounded-full before:bg-[#10302c] dark:before:bg-[#143632] ' . $focusWindow;
$quickCategories = array_slice($categories, 0, 8);
$queryUrl = static function (array $changes = []) use ($filters): string {
    $query = [
        'search' => (string) ($filters['search'] ?? ''),
        'category_id' => (int) ($filters['category_id'] ?? 0),
        'brand_id' => (int) ($filters['brand_id'] ?? 0),
        'sort' => (string) ($filters['sort'] ?? 'newest'),
        'page' => (int) ($filters['page'] ?? 1),
    ];
    foreach ($changes as $key => $value) {
        $query[$key] = $value;
    }
    if ($query['search'] === '') {
        unset($query['search']);
    }
    if ($query['category_id'] === 0) {
        unset($query['category_id']);
    }
    if ($query['brand_id'] === 0) {
        unset($query['brand_id']);
    }
    if ($query['sort'] === 'newest') {
        unset($query['sort']);
    }
    if ($query['page'] === 1) {
        unset($query['page']);
    }
    $encoded = http_build_query($query);

    return '/boutique' . ($encoded === '' ? '' : '?' . $encoded);
};
$pageTitle = $selectedCategory !== null
    ? 'Boutique · ' . (string) $selectedCategory['name']
    : ($selectedBrand !== null ? 'Boutique · ' . (string) $selectedBrand['name'] : 'Boutique');
?>
@title($pageTitle . ' | ' . app()->config('app.name'))
@description('Recherchez et parcourez les produits et services, avec des filtres par catégorie et par marque.')
@meta('csrf_token', $csrf_token ?? "")
@if($hasFilters)
@meta('robots', 'noindex,follow')
@else
@if($canonical !== '')
@canonical($canonical)
@endif
@meta('robots', 'index,follow')
@endif
@og('type', 'website')
@og('title', $pageTitle)
@og('description', 'Parcourez les produits et services, recherchez un article et filtrez par catégorie ou par marque.')

@section('content')
@cdn('scripts', '/dashboard/service-assets/inventory-service/product-modal.js')
@cdn('scripts', '/dashboard/service-assets/inventory-service/storefront-search.js')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
@include('partials.site-nav', ['active' => 'shop'])
<main class="min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc] [font-family:Anybody,ui-sans-serif,system-ui,sans-serif]" data-storefront-root>
    <div class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-x-2 text-sm" aria-label="Fil d’Ariane">
            <a class="rounded underline underline-offset-4 <?= $focus ?>" href="/">Accueil</a>
            <span aria-hidden="true">/</span>
            <span class="font-bold" aria-current="page"><?= $escape($pageTitle) ?></span>
        </nav>

        <!-- Banner : marquise + vitrine avec recherche intégrée -->
        <header class="relative mt-5">
            <div class="relative z-10 flex" aria-hidden="true">
                <?php for ($i = 0; $i < 10; $i++): ?>
                    <span class="h-12 flex-1 rounded-b-[1.5rem] shadow-[0_6px_0_#10302c] dark:shadow-[0_6px_0_#3a5f59] <?= $i % 2 === 0 ? 'bg-[#ff7a1a]' : 'bg-white dark:bg-[#cfe8dc]' ?>"></span>
                <?php endfor; ?>
            </div>
            <div class="-mt-5 rounded-b-md bg-[#10302c] px-6 pb-8 pt-12 text-[#cfe8dc] shadow-[0_8px_0_#0c1c1a] dark:bg-[#143632] dark:shadow-[0_8px_0_#3a5f59] sm:px-10 sm:pt-14">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div class="min-w-0">
                        <h1 class="break-words text-5xl font-extrabold leading-[0.9] [font-stretch:75%] sm:text-7xl"><?= $escape($pageTitle) ?></h1>
                        <p class="mt-4 text-lg" role="status" aria-live="polite"><span class="font-extrabold tabular-nums text-[#ff7a1a]" data-storefront-result-count><?= $total ?></span> <span data-storefront-result-label><?= $total === 1 ? 'résultat' : 'résultats' ?></span></p>
                    </div>
                    <form action="/boutique" method="get" role="search" data-storefront-form="search" class="flex w-full flex-col gap-3 sm:flex-row lg:max-w-xl">
                        <?php if ($categoryId > 0): ?><input type="hidden" name="category_id" value="<?= $categoryId ?>"><?php endif; ?>
                        <?php if ($brandId > 0): ?><input type="hidden" name="brand_id" value="<?= $brandId ?>"><?php endif; ?>
                        <?php if ($sort !== 'newest'): ?><input type="hidden" name="sort" value="<?= $escape($sort) ?>"><?php endif; ?>
                        <label for="banner-search" class="sr-only">Rechercher un produit ou un service</label>
                        <input id="banner-search" name="search" type="search" data-storefront-search-field value="<?= $escape($search) ?>" placeholder="Que cherchez-vous ?" class="min-h-11 w-full min-w-0 rounded-md border-2 border-[#cfe8dc] bg-[#cfe8dc] px-4 text-[#10302c] placeholder:text-[#10302c]/60 <?= $focusWindow ?>">
                        <button type="submit" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-md bg-[#ff7a1a] px-6 text-base font-bold text-[#10302c] shadow-[0_4px_0_#0c1c1a] transition hover:-translate-y-0.5 <?= $focusWindow ?>">Rechercher</button>
                    </form>
                </div>

                <nav class="mt-7 border-t-2 border-dashed border-[#cfe8dc]/30 pt-5" aria-label="Rayons" data-storefront-category-nav <?= $quickCategories === [] ? 'hidden' : '' ?>>
                        <ul class="flex gap-3 overflow-x-auto pb-2 [scrollbar-width:thin]" data-storefront-category-links data-link-class="<?= $escape($windowChip) ?>">
                            <li class="shrink-0"><a class="<?= $windowChip ?>" href="<?= $escape($queryUrl(['category_id' => 0, 'page' => 1])) ?>" <?= $categoryId === 0 ? 'aria-current="true"' : '' ?>>Tout<?= $categoryId === 0 ? '<span class="sr-only"> (sélectionné)</span>' : '' ?></a></li>
                            <?php foreach ($quickCategories as $category): ?>
                                <li class="shrink-0"><a class="<?= $windowChip ?>" href="<?= $escape($queryUrl(['category_id' => (int) $category['id'], 'page' => 1])) ?>" <?= $categoryId === (int) $category['id'] ? 'aria-current="true"' : '' ?>><?= $escape($category['name']) ?><?= $categoryId === (int) $category['id'] ? '<span class="sr-only"> (sélectionné)</span>' : '' ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
            </div>
            @include('partials.public.trending-banner', ['trending_products' => $trending_products ?? []])
        </header>

        <section class="mt-10 rounded-md bg-white p-5 shadow-[0_6px_0_#10302c] dark:bg-[#143632] dark:shadow-[0_6px_0_#3a5f59] sm:p-6" aria-label="Filtres">
            <form action="/boutique" method="get" data-storefront-form="filters" class="grid gap-4 md:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                <input type="hidden" name="search" value="<?= $escape($search) ?>">
                <div class="min-w-0">
                    <label for="catalog-category" class="mb-1.5 block text-sm font-bold">Catégorie</label>
                    <select id="catalog-category" name="category_id" data-storefront-category-field class="<?= $field ?>">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= $escape($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="min-w-0">
                    <label for="catalog-brand" class="mb-1.5 block text-sm font-bold">Marque</label>
                    <select id="catalog-brand" name="brand_id" data-storefront-brand-field class="<?= $field ?>">
                        <option value="">Toutes les marques</option>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?= (int) $brand['id'] ?>" <?= $brandId === (int) $brand['id'] ? 'selected' : '' ?>><?= $escape($brand['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="min-w-0">
                    <label for="catalog-sort" class="mb-1.5 block text-sm font-bold">Trier par</label>
                    <select id="catalog-sort" name="sort" data-storefront-sort-field class="<?= $field ?>">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Ajouts récents</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Nom croissant</option>
                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Nom décroissant</option>
                    </select>
                </div>
                <button class="inline-flex min-h-11 items-center justify-center rounded-md bg-[#ff7a1a] px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:shadow-[0_4px_0_#3a5f59] <?= $focusPanel ?>" type="submit">Appliquer</button>
            </form>

            <div data-storefront-active-filters>
                @include('partials.public.storefront-active-filters', [
                    'storefront_filters' => $filters,
                    'storefront_selected_category' => $selectedCategory,
                    'storefront_selected_brand' => $selectedBrand,
                ])
            </div>
            <p class="mt-3 text-sm" data-storefront-status role="status" aria-live="polite" tabindex="-1" hidden></p>
        </section>
        <div class="scroll-mt-24" data-storefront-results-container aria-busy="false">
            @include('public.catalog.storefront-results', [
                'storefront_filters' => $filters,
                'storefront_listing' => $listing,
            ])
        </div>
    </div>
</main>
<footer class="shelf border-t-2 border-[#10302c]/20 bg-[#cfe8dc] text-[#10302c] dark:border-[#cfe8dc]/20 dark:bg-[#0c1c1a] dark:text-[#cfe8dc]">
    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-8 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
        <p>&copy; <?= date('Y') ?> <?= $escape(app()->config('app.name')) ?></p>
        <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Navigation de pied de page">
            <a class="inline-flex min-h-11 items-center rounded underline underline-offset-4 <?= $focus ?>" href="/">Accueil</a>
            <?php if ($categories !== []): ?><a class="inline-flex min-h-11 items-center rounded underline underline-offset-4 <?= $focus ?>" href="/categories">Catégories</a><?php endif; ?>
            <?php if ($brands !== []): ?><a class="inline-flex min-h-11 items-center rounded underline underline-offset-4 <?= $focus ?>" href="/brands">Marques</a><?php endif; ?>
        </nav>
    </div>
</footer>
@endsection