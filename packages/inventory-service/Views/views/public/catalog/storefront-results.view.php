<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = is_array($storefront_filters ?? null) ? $storefront_filters : [];
$listing = is_array($storefront_listing ?? null) ? $storefront_listing : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$products = array_values(is_array($listing['items'] ?? null) ? $listing['items'] : []);
$search = (string) ($filters['search'] ?? '');
$categoryId = (int) ($filters['category_id'] ?? 0);
$brandId = (int) ($filters['brand_id'] ?? 0);
$hasFilters = $search !== '' || $categoryId > 0 || $brandId > 0;
$page = (int) ($listing['page'] ?? 1);
$pages = max(1, (int) ($listing['pages'] ?? 1));
$btnPrimary = 'inline-flex min-h-11 items-center justify-center rounded-md bg-[#ff7a1a] px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:shadow-[0_4px_0_#3a5f59] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$pagerLink = 'inline-flex min-h-11 items-center justify-center rounded-md bg-white px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_4px_0_#3a5f59] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:focus-visible:ring-offset-[#0c1c1a]';
$shelves = array_chunk($products, 5);
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
?>
<section class="mt-12" aria-labelledby="storefront-results-title">
    <h2 id="storefront-results-title" class="sr-only">Résultats de la boutique</h2>
    <?php if ($products === []): ?>
        <div class="rounded-md border-4 border-dashed border-[#10302c] px-6 py-16 text-center dark:border-[#3a5f59]">
            <?php if ($hasFilters): ?>
                <p class="text-3xl font-extrabold [font-stretch:80%]">Aucun résultat pour cette sélection</p>
                <p class="mx-auto mt-2 max-w-sm">Modifiez votre recherche ou retirez un filtre pour élargir les résultats.</p>
                <a class="mt-6 <?= $btnPrimary ?>" href="/boutique">Effacer les filtres</a>
            <?php else: ?>
                <p class="text-3xl font-extrabold [font-stretch:80%]">Le catalogue se prépare</p>
                <p class="mx-auto mt-2 max-w-sm">Les produits et services publiés apparaîtront ici.</p>
                <a class="mt-6 <?= $btnPrimary ?>" href="/">Retour à l’accueil</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="space-y-10">
            <?php foreach ($shelves as $shelfIndex => $shelf): ?>
                <div class="-mx-4 overflow-x-auto pb-3 [scrollbar-width:thin] sm:mx-0" role="region" tabindex="0" aria-label="Résultats, rangée <?= $shelfIndex + 1 ?>">
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
            <nav class="mt-12 flex flex-wrap items-center justify-between gap-4" aria-label="Pagination des résultats">
                <?php if ($page > 1): ?><a class="<?= $pagerLink ?>" href="<?= $escape($queryUrl(['page' => $page - 1])) ?>" rel="prev">Précédent</a><?php else: ?><span></span><?php endif; ?>
                <span class="text-sm">Page <span class="font-extrabold tabular-nums"><?= $page ?></span> sur <span class="font-extrabold tabular-nums"><?= $pages ?></span></span>
                <?php if ($page < $pages): ?><a class="<?= $pagerLink ?>" href="<?= $escape($queryUrl(['page' => $page + 1])) ?>" rel="next">Suivant</a><?php else: ?><span></span><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
