@extends('layouts.app')
@meta('csrf_token', $csrf_token)
@meta('csrf_token', $csrf_token)
<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$kindLabel = ($reference_kind ?? '') === 'brand' ? 'Marque' : 'Catégorie';
$seoBaseUrl = rtrim((string) app()->environment()->get('APP_URL', ''), '/');
$seoPath = ($reference_kind ?? '') === 'brand' ? '/brands/' : '/categories/';
$pagination = is_array($reference_pagination ?? null) ? $reference_pagination : ['page' => 1, 'pages' => 1, 'total' => count($reference_products ?? [])];
$page = (int) ($pagination['page'] ?? 1);
$canonical = $seoBaseUrl . $seoPath . rawurlencode((string) ($reference['slug'] ?? ''));
if ($page > 1) {
    $canonical .= '?page=' . $page;
}
$canonical = filter_var($canonical, FILTER_VALIDATE_URL) ? $canonical : '';
$seoTags = \App\Catalog\SeoTags::parse($reference['seo_tags'] ?? '');
$seoKeywords = implode(', ', $seoTags);
$description = 'Découvrez les produits ' . strtolower($kindLabel) . ' ' . (string) ($reference['name'] ?? '') . '.';
$itemList = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => (string) ($reference['name'] ?? ''),
    'description' => $description,
    'mainEntity' => [
        '@type' => 'ItemList',
        'itemListElement' => array_map(
            static fn(int $index, $product): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => $seoBaseUrl . '/products/' . rawurlencode((string) $product['slug']),
                'name' => (string) $product['name'],
            ],
            array_keys($reference_products ?? []),
            $reference_products ?? [],
        ),
    ],
];
$total = (int) ($pagination['total'] ?? count($reference_products ?? []));
$totalLabel = $total > 1 ? 'produits' : 'produit';
$pages = max(1, (int) ($pagination['pages'] ?? 1));
$baseHref = $seoPath . rawurlencode((string) ($reference['slug'] ?? ''));
$kindPlural = $escape($kindLabel) . 's';
$kindLower = strtolower($kindLabel);
?>
@title(($reference['name'] ?? $kindLabel) . ' | ' . app()->config('app.name'))
@description($description)
@if($seoKeywords !== '')
@meta('keywords', $seoKeywords)
@endif
@if($canonical !== '')
@canonical($canonical)
@endif
@meta('robots', 'index,follow')
@og('type', 'website')
@og('title', (string) ($reference['name'] ?? $kindLabel))
@og('description', $description)
@if($canonical !== '')
@og('url', $canonical)
@endif
@push('head')
<script
    type="application/ld+json"><?= json_encode($itemList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
@endpush

@section('content')
@cdn('scripts', '/dashboard/service-assets/inventory-service/product-modal.js')
@cdn('scripts', '/dashboard/service-assets/inventory-service/api-client.js')
<?php if (($quote_requests_enabled ?? false) === true): ?>
@cdn('scripts', '/dashboard/service-assets/quotes/quote-request.js')
<?php endif; ?>
<?php
$focus = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$pagerLink = 'inline-flex min-h-11 items-center rounded-md bg-white px-5 font-bold text-[#10302c] shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 hover:bg-[#ff7a1a] dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] dark:hover:bg-[#ff7a1a] ' . $focus;
$products = array_values($reference_products ?? []);
$shelves = array_chunk($products, 5);
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">
@include('partials.site-nav', ['active' => ($reference_kind ?? '') === 'brand' ? 'brands' : 'categories'])
<main class="min-h-screen bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc] [font-family:Anybody,ui-sans-serif,system-ui,sans-serif]">
    <div class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8">
        <nav class="flex flex-wrap items-center gap-x-2 text-sm" aria-label="Fil d’Ariane">
            <a class="rounded underline underline-offset-4 <?= $focus ?>" href="/">Accueil</a>
            <span aria-hidden="true">/</span>
            <a class="rounded underline underline-offset-4 <?= $focus ?>" href="<?= $escape($seoPath) ?>"><?= $kindPlural ?></a>
            <span aria-hidden="true">/</span>
            <span class="font-bold" aria-current="page"><?= $escape($reference['name'] ?? '') ?></span>
        </nav>

        <header class="mt-4 grid gap-10 lg:grid-cols-[minmax(0,1fr)_16rem] lg:items-end">
            <div class="min-w-0">
                <div class="origin-top rotate-[-1.2deg] w-fit max-w-full">
                    <div class="flex justify-between px-8" aria-hidden="true">
                        <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                        <span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span>
                    </div>
                    <h1 class="break-words rounded-xl bg-[#ff7a1a] px-6 py-4 text-4xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:80%] dark:shadow-[0_6px_0_#3a5f59] sm:px-8 sm:text-7xl"><?= $escape($reference['name'] ?? '') ?></h1>
                </div>

                <div class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <span class="rounded-md bg-[#10302c] px-3 py-1 text-sm font-bold text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]"><?= $escape($kindLabel) ?></span>
                    <p class="text-sm"><span class="font-extrabold tabular-nums"><?= $total ?></span> <?= $totalLabel ?></p>
                </div>
                <p class="mt-4 max-w-md text-lg leading-snug"><?= $escape($description) ?></p>
                <div class="mt-6">
                    <?php if (($quote_requests_enabled ?? false) === true): ?>
                        @include('partials.public.quote-request', [
                            'quote_source' => ($reference_kind ?? '') === 'brand' ? 'brand' : 'category',
                            'quote_category_id' => ($reference_kind ?? '') === 'category' ? (int) ($reference['id'] ?? 0) : 0,
                            'quote_brand_id' => ($reference_kind ?? '') === 'brand' ? (int) ($reference['id'] ?? 0) : 0,
                        ])
                    <?php endif; ?>
                </div>

                <?php if ($seoTags !== []): ?>
                    <ul class="mt-6 flex flex-wrap gap-x-3 gap-y-3" aria-label="Mots-clés">
                        <?php foreach ($seoTags as $keyword): ?>
                            <li>
                                <a class="relative inline-flex min-h-9 items-center rounded-md bg-white py-1.5 pl-9 pr-4 text-sm font-bold shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] before:absolute before:left-3 before:top-1/2 before:h-2.5 before:w-2.5 before:-translate-y-1/2 before:rounded-full before:bg-[#cfe8dc] before:ring-2 before:ring-[#10302c] dark:before:bg-[#0c1c1a] <?= $focus ?>" href="/tags/<?= $escape(rawurlencode(\App\Catalog\SeoTags::slug($keyword))) ?>"><?= $escape($keyword) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php if (is_array($hero_product ?? null)): ?>
                <aside class="mx-auto w-full max-w-[13rem] sm:max-w-[16rem] lg:mx-0" aria-label="Produit ou service à la une">
                    <p class="mb-3 text-2xl font-extrabold [font-stretch:80%]">À la une</p>
                    <div class="border-b-[14px] border-[#10302c] dark:border-[#3a5f59]">
                        @include('partials.public.product-card', ['product' => $hero_product, 'escape' => $escape])
                    </div>
                </aside>
            <?php endif; ?>
        </header>

        <section class="mt-16" aria-labelledby="reference-products-title">
            <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="reference-products-title" class="text-3xl font-extrabold [font-stretch:80%] sm:text-4xl">Produits associés</h2>
                <p class="text-sm"><span class="font-extrabold tabular-nums"><?= $total ?></span> au total</p>
            </div>

            <?php if ($products === []): ?>
                <div class="rounded-2xl border-4 border-dashed border-[#10302c] px-6 py-16 text-center dark:border-[#3a5f59]">
                    <p class="text-3xl font-extrabold [font-stretch:80%]">Rien à découvrir ici pour l’instant</p>
                    <p class="mx-auto mt-2 max-w-sm">Aucun produit actif n’est associé à cette <?= $escape($kindLower) ?>.</p>
                    <a class="mt-6 inline-flex min-h-11 items-center rounded-md bg-[#ff7a1a] px-6 font-bold text-[#10302c] shadow-[0_4px_0_#10302c] dark:shadow-[0_4px_0_#3a5f59] <?= $focus ?>" href="<?= $escape($seoPath) ?>">Voir les autres <?= $escape($kindLower) ?>s</a>
                </div>
            <?php else: ?>
                <div class="space-y-10">
                    <?php foreach ($shelves as $shelfIndex => $shelf): ?>
                        <div class="-mx-4 overflow-x-auto pb-3 [scrollbar-width:thin] sm:mx-0" role="region" tabindex="0" aria-label="Produits associés, rangée <?= $shelfIndex + 1 ?>">
                            <div class="w-max min-w-full border-b-[14px] border-[#10302c] px-4 dark:border-[#3a5f59] sm:px-0">
                                <ul class="flex items-end gap-3">
                                    <?php foreach ($shelf as $product): ?>
                                        <li class="w-44 shrink-0 sm:w-52">
                                            @include('partials.public.product-card', ['product' => $product, 'escape' => $escape, 'show_preview' => true])
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($pages > 1): ?>
                    <nav class="mt-14 flex items-center justify-between gap-4" aria-label="Pagination des produits">
                        <?php if ($page > 1): ?><a class="<?= $pagerLink ?>" href="<?= $escape($baseHref . '?page=' . ($page - 1)) ?>" rel="prev">Précédent</a><?php else: ?><span></span><?php endif; ?>
                        <span class="text-sm">Page <span class="font-extrabold tabular-nums"><?= $page ?></span> sur <?= $pages ?></span>
                        <?php if ($page < $pages): ?><a class="<?= $pagerLink ?>" href="<?= $escape($baseHref . '?page=' . ($page + 1)) ?>" rel="next">Suivant</a><?php else: ?><span></span><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</main>
@endsection