<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$json = static fn(mixed $value): string => htmlspecialchars((string) json_encode($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/* ---------- Icônes ---------- */
$iconPaths = [
    'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
    'package' => '<path d="m7.5 4.27 9 5.15M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
    'trash' => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/>',
    'image' => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
    'images' => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2"/>',
    'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
    'video' => '<path d="m16 13 5.22 3.48a.5.5 0 0 0 .78-.42V7.94a.5.5 0 0 0-.78-.42L16 11"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
    'link' => '<path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5"/>',
];
$icon = static fn(string $name, string $class = 'h-4 w-4') => '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($iconPaths[$name] ?? '') . '</svg>';

/* ---------- Permissions ---------- */
$has = static fn(string $permission): bool => in_array($permission, $admin_permissions ?? [], true);
$canCreate = $has('catalog.product.create');
$canUpdate = $has('catalog.product.update');
$canArchive = $has('catalog.product.archive');
$canDelete = $has('catalog.product.delete');
$canManageCategories = $has('catalog.category.manage');
$canManageBrands = $has('catalog.brand.manage');
$canManageMedia = $has('catalog.media.upload');
$canViewDiscounts = $has('inventory.discount.view') || $has('inventory.discount.manage');
$canManageDiscounts = $has('inventory.discount.manage');
$canViewStock = $has('inventory.stock.view');
$canReceiveStock = $has('inventory.stock.receive');
$canIssueStock = $has('inventory.stock.issue');
$canManageStock = $canReceiveStock || $canIssueStock;

/*
 * Système visuel : zinc pour les surfaces, encre (zinc-900) pour l'action principale,
 * bleu uniquement pour ce qui est interactif ou remisé. Rayons : md = contrôles, lg = panneaux.
 * Pas d'ombres sur la page : la hiérarchie vient des bordures et de l'espacement.
 */
$ui = [
    'panel' => 'overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950',
    'field' => 'mt-1.5 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 transition focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/25 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-blue-400 dark:focus:ring-blue-400/25',
    'label' => 'block text-sm font-medium text-zinc-700 dark:text-zinc-300',
    'primary' => 'inline-flex items-center justify-center gap-2 rounded-md bg-zinc-900 px-3.5 py-2 text-sm font-medium text-white transition hover:bg-zinc-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200 dark:focus-visible:ring-blue-400 dark:focus-visible:ring-offset-zinc-950',
    'secondary' => 'inline-flex items-center justify-center gap-2 rounded-md border border-zinc-300 bg-white px-3.5 py-2 text-sm font-medium text-zinc-800 transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800 dark:focus-visible:ring-blue-400',
    'iconBtn' => 'inline-flex h-8 w-8 items-center justify-center rounded-md text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white dark:focus-visible:ring-blue-400',
    'menu' => 'flex w-full cursor-pointer items-center rounded-md px-2.5 py-1.5 text-left text-sm text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800',
    'menuWarn' => 'flex w-full cursor-pointer items-center rounded-md px-2.5 py-1.5 text-left text-sm text-amber-700 transition-colors hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-400/10',
    'menuOk' => 'flex w-full cursor-pointer items-center rounded-md px-2.5 py-1.5 text-left text-sm text-blue-700 transition-colors hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-400/10',
    'menuDanger' => 'flex w-full cursor-pointer items-center rounded-md px-2.5 py-1.5 text-left text-sm text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-400/10',
    'menuSep' => 'my-1 h-px bg-zinc-200 dark:bg-zinc-800',
    'cell' => 'px-4 py-3 align-middle',
    'muted' => 'text-zinc-600 dark:text-zinc-400',
];
$statusLabels = ['draft' => 'En préparation', 'active' => 'Actif', 'archived' => 'Retiré du catalogue'];
$productColumns = [
    ['key' => 'product', 'label' => 'Produit', 'sortable' => false],
    ['key' => 'sku', 'label' => 'Référence', 'sortable' => false],
    ['key' => 'category_name', 'label' => 'Catégorie', 'sortable' => false],
    ['key' => 'brand_name', 'label' => 'Marque', 'sortable' => false],
    ['key' => 'status', 'label' => 'Statut', 'sortable' => false],
    ...($canViewStock ? [['key' => 'stock', 'label' => 'Stock', 'sortable' => false]] : []),
    ['key' => 'price', 'label' => 'Prix indicatif', 'sortable' => false],
    ...($canViewDiscounts ? [['key' => 'discount', 'label' => 'Réduction', 'sortable' => false]] : []),
    ['key' => 'actions', 'label' => 'Actions', 'align' => 'right', 'sortable' => false],
];
?>
@cdn('scripts', '/dashboard/service-assets/inventory-service/catalog-management.js')
@cdn('scripts', '/dashboard/service-assets/inventory-service/discount-management.js')
<style>
    [x-cloak] {
        display: none !important;
    }
</style>

<x-container title="" :reduiceable="false" size="xxl" class="my-1" :attributes="['x-data'=>'',
'x-data'=>'$store.catalog.initialize({filters:'. $json($catalog_filters ?? []) .', products: '. $json($catalog_result['items'] ?? []) .', categories:'. $json($catalog_categories ?? []) .', brands: '. $json($catalog_brands ?? []) .', editProduct:'. $json($catalog_product ?? null) .', page: '. (int) ($catalog_result['page'] ?? 1) .', pages: '. (int) ($catalog_result['pages'] ?? 1) .', total: '. (int) ($catalog_result['total'] ?? 0) .', perPage: '. (int) ($catalog_result['per_page'] ?? 5) .', media:'. $json($catalog_media ?? []) .', stockUnits: '. $json($catalog_stock_units ?? []) .', categoryCreationToken: '. $json($catalog_category_creation_token ?? '') .', productCreationToken: '. $json($catalog_creation_token ?? '') .', canArchive: '. ($canArchive ? 'true' : 'false') .', canViewDiscounts: '. ($canViewDiscounts ? 'true' : 'false') .', canViewStock: '. ($canViewStock ? 'true' : 'false') .', canReceiveStock: '. ($canReceiveStock ? 'true' : 'false') .', canIssueStock: '. ($canIssueStock ? 'true' : 'false') .'})',
        'data-can-view-discounts' => $canViewDiscounts ? '1' : '0',
        'data-discount-scope'=>'',
        'aria-labelledby'=>'categories-title',
        ]">
    <x-slot:actions>
        <x-select parent_class="flex flex-col gap-1" xModel="$store.catalog.filters.category_id"
            :attributes="['@change' => '$store.catalog.applyFilters()']"
            :options="['all' => 'Toutes les catégories'] + $catalog_categories" option-value="id" option-label="name"
            :selected="(string) ($catalog_filters['category_id'] ?? 'all')" />
        <x-select parent_class="flex flex-col gap-1" xModel="$store.catalog.filters.brand_id"
            :attributes="['@change' => '$store.catalog.applyFilters()']"
            :options="['all' => 'Toutes les marques'] + $catalog_brands" option-value="id" option-label="name"
            :selected="(string) ($catalog_filters['brand_id'] ?? 'all')" />

        <x-select parent_class="flex flex-col gap-1" xModel="$store.catalog.filters.status"
            :attributes="['@change'=>'$store.catalog.applyFilters()']" :options="[
                    ['label'=>'Tous les status','value'=>'all'],
                    ['label'=>'En préparation','value'=>'draft'],
                    ['label'=>'Actif','value'=>'active'],
                    ['label'=>'Retiré du catalogue','value'=>'archived'],
                ]" />
        <x-select parent_class="flex flex-col gap-1" xModel="$store.catalog.filters.kind"
            :attributes="['@change' => '$store.catalog.applyFilters()']"
            :selected="(string) ($catalog_filters['kind'] ?? 'all')" :options="[
        'all'     => 'Tous les types',
        'goods'   => 'Produits',
        'service' => 'Services',
    ]" />
        <x-input name="search" id="category-search" autocomplete="off" type="search"
            placeholder="Rechercher un produit…" parent_class="flex flex-col gap-1"
            :attributes="['x-model'=>'$store.catalog.filters.search', '@input.debounce.300ms'=>'$store.catalog.applyFilters()']" />

        <x-button label="Réinitialiser" class="refresh" icon="refresh" :passed="!$canCreate"
            click="$store.catalog.clearFilters()" />
        <x-button label="Nouveau" class="add" icon="new" :passed="!$canCreate"
            click="$store.catalog.openNewProduct()" />
    </x-slot:actions>
    <x-alert :passed="!($catalog_success ?? '') !== ''" :message="$escape($catalog_success)" variant="success" />
    <x-alert :passed="!($catalog_error ?? '') !== ''" :message="$escape($catalog_error)" variant="warning" />
    <?php if (!empty($catalog_form_errors)): ?>
        <?php
        $catalogFieldLabels = [
            'name' => 'Nom du produit',
            'slug' => 'Slug',
            'description' => 'Description',
            'seo_tags' => 'Tags SEO',
            'kind' => 'Nature',
            'status' => 'Statut',
            'sku' => 'Référence',
            'price' => 'Prix',
            'cost_price' => 'Coût d’achat',
            'currency' => 'Devise',
            'category_id' => 'Catégorie',
            'brand_id' => 'Marque',
            'manufacturer' => 'Fabricant',
            'creation_token' => 'Formulaire',
            'stock_unit_id' => 'Unité de stock',
        ];
        ?>
        <div class="my-4 rounded-md border border-red-200 border-l-4 border-l-red-500 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-500/20 dark:border-l-red-500 dark:bg-red-500/10 dark:text-red-200"
            role="alert" aria-live="polite">
            <p class="font-semibold">Corrigez les champs suivants</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 marker:text-red-400">
                <?php foreach ($catalog_form_errors as $field => $messages): ?>
                    <?php foreach ((array) $messages as $message): ?>
                        <li><strong class="font-medium"><?= $escape($catalogFieldLabels[$field] ?? $field) ?> :</strong>
                            <?= $escape($message) ?></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- ================= Liste des produits ================= -->
    <x-slot:components>
        <section class="<?= $ui['panel'] ?>">
            <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-zinc-200 px-4 py-4 dark:border-zinc-800 sm:px-5">
                <h2 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">Catalogue</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    <span class="text-xl font-semibold tabular-nums tracking-tight text-zinc-900 dark:text-white"
                        x-text="$store.catalog.total"></span>
                    <span class="ml-1">résultat(s)</span>
                </p>
            </header>

            <x-table id="catalog-products" variant="plain" store="catalog" store-rows-key="products"
                store-total-key="total" store-loading-key="loading" store-query-method="query" store-autoload
                :columns="$productColumns" empty-message="Aucun produit" sortable sticky-header
                max-height="60vh" responsive="stack" stack-below="md" :page-size="5" :page-sizes="[5, 10, 25, 50, 100]"
                no-results-message="Aucun résultat trouvé">
                <x-slot:tbody>
                    <template x-for="item in $store.catalog.products" :key="item.id">
                        <tr data-catalog-row
                            class="border-t border-zinc-100 transition-colors first:border-t-0 hover:bg-zinc-50 dark:border-zinc-800/70 dark:hover:bg-zinc-900/60">
                            <td class="<?= $ui['cell'] ?>">
                                <div class="flex items-center gap-3">
                                    <span aria-hidden="true"
                                        class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-zinc-100 text-sm font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                                        x-text="(item.name || '?').charAt(0).toUpperCase()"></span>
                                    <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="font-medium text-zinc-900 dark:text-white" x-text="item.name"></span>
                                        <span x-show="item.kind === 'service'"
                                            class="rounded border border-violet-300 px-1.5 py-px text-xs font-medium text-violet-700 dark:border-violet-400/40 dark:text-violet-300">Service</span>
                                        <span x-show="Number(item.is_trending) === 1"
                                            class="rounded border border-amber-300 px-1.5 py-px text-xs font-medium text-amber-700 dark:border-amber-400/40 dark:text-amber-300">Tendance</span>
                                        <span class="basis-full text-xs font-normal text-zinc-500 dark:text-zinc-400"
                                            x-text="`${Number(item.views_count || 0).toLocaleString('fr-FR')} visite(s)`"></span>
                                    </div>
                                </div>
                            </td>
                            <td class="<?= $ui['cell'] ?> font-mono text-xs <?= $ui['muted'] ?>" x-text="item.sku || ''"></td>
                            <td class="<?= $ui['cell'] ?> text-sm <?= $ui['muted'] ?>" x-text="item.category_name || '—'"></td>
                            <td class="<?= $ui['cell'] ?> text-sm <?= $ui['muted'] ?>" x-text="item.brand_name || '—'"></td>
                            <td class="<?= $ui['cell'] ?>">
                                <span class="inline-flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    <span class="h-2 w-2 shrink-0 rounded-full" :class="{
                                        draft: 'bg-zinc-400 dark:bg-zinc-500',
                                        active: 'bg-emerald-500',
                                        archived: 'bg-amber-500'
                                    }[item.status] || 'bg-zinc-400'"></span>
                                    <span
                                        x-text="{draft: 'En préparation', active: 'Actif', archived: 'Retiré du catalogue'}[item.status] || item.status"></span>
                                </span>
                            </td>
                            <?php if ($canViewStock): ?>
                                <td class="<?= $ui['cell'] ?> whitespace-nowrap text-sm tabular-nums text-zinc-700 dark:text-zinc-300"
                                    x-text="item.kind !== 'goods' || item.status !== 'active' ? '—' : $store.catalog.stockQuantity(item)"></td>
                            <?php endif; ?>
                            <td class="<?= $ui['cell'] ?> whitespace-nowrap text-sm font-medium tabular-nums text-zinc-900 dark:text-white"
                                x-text="item.price === null ? '—' : `${item.price} ${item.currency}`"></td>
                            <?php if ($canViewDiscounts): ?>
                                <td x-init="$store.catalog.loadDiscount(item)" class="<?= $ui['cell'] ?> min-w-36 text-sm">
                                    <button x-show="item.kind !== 'service' && $store.catalog.hasDiscount(item)"
                                        type="button"
                                        class="inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 transition hover:bg-blue-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 dark:bg-blue-400/10 dark:text-blue-300 dark:hover:bg-blue-400/20"
                                        @click="$store.catalog.openDiscountPrice(item)"
                                        x-text="`Prix remisé : ${$store.catalog.discountSummary(item)}`"
                                        aria-label="Afficher les prix avec et sans réduction"></button>
                                    <span x-show="item.kind === 'service' || !$store.catalog.hasDiscount(item)"
                                        class="<?= $ui['muted'] ?>" x-text="$store.catalog.discountSummary(item)"
                                        aria-live="polite"></span>
                                </td>
                            <?php endif; ?>
                            <td class="<?= $ui['cell'] ?> whitespace-nowrap text-right">
                                <x-action-menu>
                                    <x-button :passed="!$canUpdate" title="Modifier" label="Modifier"
                                        :class="$ui['menu']" type="button"
                                        click="$store.catalog.openProduct(item);" />
                                    <?php if ($canUpdate): ?>
                                        <button type="button" role="menuitem" class="<?= $ui['menuWarn'] ?>"
                                            @click="$store.catalog.toggleTrending(item.kind, item)"
                                            x-text="Number(item.is_trending) === 1 ? 'Retirer des tendances' : 'Mettre en tendance'"></button>
                                    <?php endif; ?>

                                    <?php if ($canManageStock): ?>
                                        <button x-show="item.kind === 'goods' && item.status === 'active'" type="button"
                                            role="menuitem" class="<?= $ui['menu'] ?>"
                                            @click="$store.catalog.manageStock(item)">Modifier le stock</button>
                                    <?php endif; ?>

                                    <?php if ($has('inventory.discount.manage')): ?>
                                        <button type="button" role="menuitem" class="<?= $ui['menu'] ?>"
                                            data-open-discount-modal data-discount-target-type="product"
                                            :data-discount-target-id="item.id"
                                            :data-discount-target-name="item.name">Configurer les réductions</button>
                                    <?php endif; ?>

                                    <x-button :passed="!$canManageMedia" title="Gérer les médias" label="Gérer les médias"
                                        :class="$ui['menu']" type="button"
                                        click="$store.catalog.openMediaManager(item)" />

                                    <a x-show="item.status === 'active'" role="menuitem" class="<?= $ui['menu'] ?>"
                                        :href="`/products/${encodeURIComponent(item.slug)}`" target="_blank"
                                        rel="noopener">Page publique</a>
                                    <?php if (($catalog_sales_statistics_enabled ?? false) && ($has('sales.orders.view') || $has('sales.orders.manage'))): ?>
                                        <a role="menuitem" class="<?= $ui['menu'] ?>"
                                            :href="`/dashboard/sales/statistics?product_id=${encodeURIComponent(item.id)}`">Statistiques</a>
                                    <?php endif; ?>
                                    <?php if ($canArchive): ?>
                                        <div class="<?= $ui['menuSep'] ?>" role="separator"></div>
                                        <button x-show="item.status !== 'archived'" type="button" role="menuitem"
                                            class="<?= $ui['menuWarn'] ?>"
                                            @click="$store.catalog.setProductStatus(item, 'archived')">Retirer du catalogue</button>
                                        <button x-show="item.status === 'archived'" type="button" role="menuitem"
                                            class="<?= $ui['menuOk'] ?>"
                                            @click="$store.catalog.setProductStatus(item, 'active')">Restaurer</button>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <?php if (!$canArchive): ?>
                                            <div class="<?= $ui['menuSep'] ?>" role="separator"></div>
                                        <?php endif; ?>
                                        <button type="button" role="menuitem" class="<?= $ui['menuDanger'] ?>"
                                            @click="$store.catalog.deleteProduct(item)">Supprimer</button>
                                    <?php endif; ?>
                                </x-action-menu>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="$store.catalog.products.length === 0">
                        <td class="px-4 py-16 text-center text-sm text-zinc-500 dark:text-zinc-400"
                            colspan="<?= count($productColumns) ?>">
                            <span
                                class="mx-auto mb-3 grid h-11 w-11 place-items-center rounded-md border border-dashed border-zinc-300 text-zinc-400 dark:border-zinc-700 dark:text-zinc-500"><?= $icon('package', 'h-5 w-5') ?></span>
                            <?= $canCreate ? 'Aucun produit. Ajoutez votre premier produit.' : 'Aucun produit à afficher.' ?>
                        </td>
                    </tr>
                    <tr
                        x-show="$store.catalog.products.length > 0 && $store.catalog.products.filter(product => $store.catalog.matchesProduct(product)).length === 0">
                        <td class="px-4 py-16 text-center text-sm text-zinc-500 dark:text-zinc-400"
                            colspan="<?= count($productColumns) ?>">Aucun produit ne correspond aux filtres.</td>
                    </tr>
                </x-slot:tbody>
            </x-table>
        </section>
    </x-slot:components>

    <!-- ================= Médias : panneau latéral ================= -->
    <x-slot:components :passed="!$canManageMedia">
        <div x-show="$store.catalog.mediaManagerOpen" x-cloak x-transition.opacity.duration.150ms
            @click.self="$store.catalog.closeMediaManager()"
            @keydown.escape.stop.prevent="$store.catalog.closeMediaManager()"
            class="fixed inset-0 z-[70] flex justify-end bg-zinc-950/50 backdrop-blur-[2px]">
            <section role="dialog" aria-modal="true" aria-labelledby="catalog-media-modal-title" tabindex="-1"
                class="flex h-full w-full max-w-2xl flex-col border-l border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                <header class="flex items-start justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <div class="flex min-w-0 items-start gap-3">
                        <span
                            class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"><?= $icon('images', 'h-4 w-4') ?></span>
                        <div class="min-w-0">
                            <h2 id="catalog-media-modal-title"
                                class="truncate text-base font-semibold tracking-tight text-zinc-900 dark:text-white">
                                Médias de <span x-text="$store.catalog.selectedMediaProduct?.name || 'ce produit'"></span>
                            </h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Images et vidéos MP4/WebM, 10 Mo
                                maximum par fichier. Les liens externes doivent être en HTTPS.</p>
                        </div>
                    </div>
                    <button type="button" class="<?= $ui['iconBtn'] ?> shrink-0" aria-label="Fermer"
                        @click="$store.catalog.closeMediaManager()"><?= $icon('x') ?></button>
                </header>

                <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5">
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="<?= $ui['primary'] ?>" :disabled="$store.catalog.mediaLoading"
                            @click="$store.catalog.openMediaUpload($store.catalog.selectedMediaProduct.id)"><?= $icon('upload') ?>Téléverser
                            un fichier</button>
                        <button type="button" class="<?= $ui['secondary'] ?>" :disabled="$store.catalog.mediaLoading"
                            @click="$store.catalog.openMediaLink($store.catalog.selectedMediaProduct.id)"><?= $icon('link') ?>Ajouter
                            un lien</button>
                    </div>

                    <div x-show="$store.catalog.mediaError" role="alert"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-red-200 border-l-4 border-l-red-500 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-500/20 dark:border-l-red-500 dark:bg-red-500/10 dark:text-red-200">
                        <span x-text="$store.catalog.mediaError"></span>
                        <button type="button" class="<?= $ui['secondary'] ?> !px-3 !py-1.5"
                            @click="$store.catalog.loadMediaManager()">Réessayer</button>
                    </div>

                    <div x-show="$store.catalog.mediaLoading" role="status"
                        class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">Chargement des médias…</div>

                    <ul x-show="!$store.catalog.mediaLoading && !$store.catalog.mediaError && $store.catalog.media.length > 0"
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <template x-for="media in $store.catalog.media" :key="media.id">
                            <li
                                class="group overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                                <img x-show="media.media_type === 'image'" :src="media.url"
                                    :alt="media.caption || $store.catalog.selectedMediaProduct?.name || 'Image du produit'"
                                    class="aspect-[4/3] w-full object-cover" loading="lazy">
                                <a x-show="media.media_type !== 'image' && Boolean(media.external_url)" :href="media.url"
                                    target="_blank" rel="noopener noreferrer"
                                    class="flex aspect-[4/3] items-center justify-center gap-2 bg-zinc-900 text-sm font-medium text-white transition hover:bg-zinc-800 dark:bg-zinc-800 dark:hover:bg-zinc-700"><?= $icon('video', 'h-5 w-5') ?>Voir
                                    la vidéo liée</a>
                                <video x-show="media.media_type !== 'image' && !media.external_url" :src="media.url"
                                    controls preload="metadata" class="aspect-[4/3] w-full bg-zinc-950"></video>
                                <div class="flex items-center justify-between gap-2 border-t border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-950">
                                    <p class="truncate text-sm text-zinc-700 dark:text-zinc-300"
                                        x-text="media.caption || media.original_name || media.external_url"></p>
                                    <button type="button"
                                        class="<?= $ui['iconBtn'] ?> shrink-0 hover:!bg-red-50 hover:!text-red-700 dark:hover:!bg-red-500/10 dark:hover:!text-red-300"
                                        aria-label="Supprimer ce média"
                                        @click="$store.catalog.deleteMedia(media.id, $store.catalog.selectedMediaProduct.id)"><?= $icon('trash') ?></button>
                                </div>
                            </li>
                        </template>
                    </ul>
                    <div x-show="!$store.catalog.mediaLoading && !$store.catalog.mediaError && $store.catalog.media.length === 0"
                        class="rounded-lg border border-dashed border-zinc-300 px-4 py-14 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <span
                            class="mx-auto mb-3 grid h-11 w-11 place-items-center rounded-md bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500"><?= $icon('image', 'h-5 w-5') ?></span>
                        Aucun média pour le moment. Téléversez une image ou ajoutez un lien.
                    </div>
                </div>
            </section>
        </div>
    </x-slot:components>

    <!-- ================= Prix remisé ================= -->
    <x-slot:components :passed="!$canViewDiscounts">
        <?php if ($canViewDiscounts): ?>
            <div x-show="$store.catalog.discountModalData" x-cloak x-transition.opacity.duration.150ms
                @click.self="$store.catalog.closeDiscountPrice()"
                @keydown.escape.window="$store.catalog.closeDiscountPrice()"
                class="fixed inset-0 z-[80] flex items-center justify-center bg-zinc-950/50 p-4 backdrop-blur-[2px]">
                <section role="dialog" aria-modal="true" aria-labelledby="catalog-discount-price-title"
                    class="w-full max-w-sm overflow-hidden rounded-lg border border-zinc-200 bg-white text-zinc-900 shadow-2xl dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-100">
                    <header class="flex items-start justify-between gap-4 px-5 pb-3 pt-4">
                        <div class="min-w-0">
                            <h2 id="catalog-discount-price-title" class="text-base font-semibold tracking-tight">Prix du produit</h2>
                            <p class="mt-0.5 truncate text-sm text-zinc-500 dark:text-zinc-400"
                                x-text="$store.catalog.discountModalData?.productName"></p>
                        </div>
                        <button type="button" class="<?= $ui['iconBtn'] ?> -mr-2 -mt-1" aria-label="Fermer"
                            @click="$store.catalog.closeDiscountPrice()"><?= $icon('x') ?></button>
                    </header>
                    <dl class="px-5 pb-5">
                        <div class="flex items-baseline justify-between gap-4 py-2 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Sans réduction</dt>
                            <dd class="tabular-nums text-zinc-500 line-through decoration-zinc-400 dark:text-zinc-400"
                                x-text="`${$store.catalog.discountModalData?.original_price} ${$store.catalog.discountModalData?.currency}`"></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 border-y border-zinc-200 py-4 dark:border-zinc-800">
                            <dt class="text-sm font-medium text-blue-700 dark:text-blue-300">Avec réduction</dt>
                            <dd class="text-2xl font-semibold tabular-nums tracking-tight"
                                x-text="`${$store.catalog.discountModalData?.final_price} ${$store.catalog.discountModalData?.currency}`"></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 pt-3 text-sm">
                            <dt class="text-zinc-500 dark:text-zinc-400">Économie</dt>
                            <dd class="font-medium tabular-nums text-emerald-700 dark:text-emerald-400"
                                x-text="`${$store.catalog.discountModalData?.total_saving} ${$store.catalog.discountModalData?.currency}`"></dd>
                        </div>
                    </dl>
                </section>
            </div>
        <?php endif; ?>
    </x-slot:components>

    <!-- ================= Gestion des réductions ================= -->
    <x-slot:components :passed="!$canManageDiscounts">
        <?php if ($has('inventory.discount.manage')): ?>
            <dialog data-discount-modal aria-labelledby="product-discounts-title"
                class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-lg border border-zinc-200 bg-white p-0 text-zinc-900 shadow-2xl backdrop:bg-zinc-950/50 backdrop:backdrop-blur-[2px] dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-100">
                <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <header class="flex items-start justify-between gap-4 px-5 py-4 sm:px-6">
                        <div class="min-w-0">
                            <h2 id="product-discounts-title" class="text-base font-semibold tracking-tight">Réductions du produit</h2>
                            <p class="mt-0.5 truncate text-sm text-zinc-500 dark:text-zinc-400" data-discount-target-name></p>
                        </div>
                        <button type="button" data-discount-close class="<?= $ui['iconBtn'] ?> shrink-0"
                            aria-label="Fermer">×</button>
                    </header>

                    <div class="space-y-4 px-5 py-5 sm:px-6">
                        <div class="space-y-2" data-discount-list aria-live="polite"></div>
                        <button type="button" data-discount-new class="<?= $ui['primary'] ?>">Ajouter une réduction</button>
                    </div>

                    <section data-discount-editor hidden class="bg-zinc-50 px-5 py-5 dark:bg-zinc-900/50 sm:px-6">
                        <h3 data-discount-editor-title class="mb-4 text-sm font-semibold text-zinc-900 dark:text-white"></h3>
                        <form data-discount-form class="grid gap-4 sm:grid-cols-2">
                            <input type="hidden" name="discount_id">
                            <label class="<?= $ui['label'] ?>">Type
                                <select name="discount_type" data-discount-type required class="<?= $ui['field'] ?>">
                                    <option value="percentage">Pourcentage</option>
                                    <option value="fixed">Montant fixe</option>
                                </select>
                            </label>
                            <label class="<?= $ui['label'] ?>">Taux / montant
                                <input name="value" type="number" min="0.01" step="0.01" required
                                    class="<?= $ui['field'] ?> tabular-nums" placeholder="15.00">
                            </label>
                            <label data-discount-currency hidden class="<?= $ui['label'] ?>">Devise
                                <input name="currency" maxlength="3" pattern="[A-Z]{3}" class="<?= $ui['field'] ?>"
                                    placeholder="MAD">
                            </label>
                            <label class="<?= $ui['label'] ?>">Début (heure locale)
                                <input name="starts_at" type="datetime-local" step="1" required class="<?= $ui['field'] ?>">
                            </label>
                            <label class="<?= $ui['label'] ?>">Fin (heure locale)
                                <input name="ends_at" type="datetime-local" step="1" required class="<?= $ui['field'] ?>">
                            </label>
                            <label
                                class="flex items-center gap-2.5 text-sm font-medium text-zinc-700 dark:text-zinc-300 sm:col-span-2">
                                <input name="is_active" type="checkbox" value="1" checked
                                    class="h-4 w-4 rounded border-zinc-300 text-blue-600 focus:ring-2 focus:ring-blue-600/30 dark:border-zinc-600 dark:bg-zinc-900"> Réduction activée
                            </label>
                            <div class="flex justify-end gap-2 sm:col-span-2">
                                <button type="button" data-discount-cancel class="<?= $ui['secondary'] ?>">Annuler</button>
                                <button type="submit" class="<?= $ui['primary'] ?>">Enregistrer</button>
                            </div>
                        </form>
                    </section>
                </div>
            </dialog>
        <?php endif; ?>
    </x-slot:components>
</x-container>