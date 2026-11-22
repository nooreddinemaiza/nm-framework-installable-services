<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = is_array($storefront_filters ?? null) ? $storefront_filters : [];
$search = (string) ($filters['search'] ?? '');
$categoryId = (int) ($filters['category_id'] ?? 0);
$brandId = (int) ($filters['brand_id'] ?? 0);
$selectedCategory = is_array($storefront_selected_category ?? null) ? $storefront_selected_category : null;
$selectedBrand = is_array($storefront_selected_brand ?? null) ? $storefront_selected_brand : null;
$hasFilters = $search !== '' || $categoryId > 0 || $brandId > 0;
$focusPanel = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-[#143632]';
$chip = 'relative inline-flex min-h-9 items-center rounded-md bg-white py-1.5 pl-9 pr-4 text-sm font-bold shadow-[0_3px_0_#10302c] transition hover:-translate-y-0.5 dark:bg-[#cfe8dc] dark:text-[#0c1c1a] dark:shadow-[0_3px_0_#3a5f59] before:absolute before:left-3 before:top-1/2 before:h-2.5 before:w-2.5 before:-translate-y-1/2 before:rounded-full before:bg-[#cfe8dc] before:ring-2 before:ring-[#10302c] dark:before:bg-[#0c1c1a] ' . $focusPanel;
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
<?php if ($hasFilters): ?>
    <div class="mt-5 flex flex-wrap items-center gap-3 border-t-2 border-dashed border-[#10302c]/25 pt-4 dark:border-[#cfe8dc]/25">
        <ul class="flex flex-wrap gap-2" aria-label="Filtres actifs">
            <?php if ($search !== ''): ?>
                <li><a class="<?= $chip ?>" href="<?= $escape($queryUrl(['search' => '', 'page' => 1])) ?>">Recherche : <?= $escape($search) ?> <span class="ml-2" aria-label="Retirer">×</span></a></li>
            <?php endif; ?>
            <?php if ($selectedCategory !== null): ?>
                <li><a class="<?= $chip ?>" href="<?= $escape($queryUrl(['category_id' => 0, 'page' => 1])) ?>">Catégorie : <?= $escape($selectedCategory['name']) ?> <span class="ml-2" aria-label="Retirer">×</span></a></li>
            <?php endif; ?>
            <?php if ($selectedBrand !== null): ?>
                <li><a class="<?= $chip ?>" href="<?= $escape($queryUrl(['brand_id' => 0, 'page' => 1])) ?>">Marque : <?= $escape($selectedBrand['name']) ?> <span class="ml-2" aria-label="Retirer">×</span></a></li>
            <?php endif; ?>
        </ul>
        <a class="min-h-9 rounded font-bold underline underline-offset-4 <?= $focusPanel ?>" href="/boutique">Effacer les filtres</a>
    </div>
<?php endif; ?>
