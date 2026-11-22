<?php
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$json = static fn(mixed $value): string => htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$has = static fn(string $permission): bool => in_array($permission, $admin_permissions ?? [], true);
$canManage = $has('catalog.brand.manage');
$brandColumns = [
    ['key' => 'brand', 'label' => 'Marque', 'sortable' => false],
    ['key' => 'actions', 'label' => 'Actions', 'align' => 'right', 'sortable' => false],
];

$ui = [
    'panel'     => 'rounded-3xl border border-slate-200/80 bg-white shadow-sm ring-1 ring-black/[0.02] dark:border-slate-800 dark:bg-slate-900 dark:ring-white/[0.03]',
    'field'     => 'block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-teal-600 focus:outline-none focus:ring-4 focus:ring-teal-600/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-teal-400 dark:focus:ring-teal-400/20',
    'label'     => 'block text-sm font-medium text-slate-700 dark:text-slate-300',
    'primary'   => 'inline-flex items-center justify-center gap-2 rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-teal-600/40 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-teal-400 dark:text-slate-950 dark:hover:bg-teal-300',
    'secondary' => 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-slate-400/30 dark:border-slate-700 dark:bg-transparent dark:text-slate-200 dark:hover:bg-slate-800',
    'navLink'   => 'rounded-lg px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-white hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
];
?>
@cdn('scripts', '/dashboard/service-assets/inventory-service/catalog-management.js')
@cdn('scripts', '/dashboard/service-assets/inventory-service/discount-management.js')
<main
    x-data
    x-init="$store.catalog.initialize({filters: {search: <?= $json($catalog_reference_search ?? '') ?>}, categories: <?= $json($catalog_categories ?? []) ?>, brands: <?= $json($catalog_brands ?? []) ?>, brandRows: <?= $json($catalog_reference_result['items'] ?? []) ?>, brandTotal: <?= (int) ($catalog_reference_result['total'] ?? 0) ?>})"
    class="mx-auto max-w-5xl space-y-8 px-4 py-8 sm:px-6 lg:px-8"
    data-discount-scope
>
    <header class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-teal-700 dark:text-teal-300">Catalogue</p>
                <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Marques</h1>
                <p class="mt-2 max-w-xl text-sm leading-6 text-slate-600 dark:text-slate-400">Gérez vos marques et retrouvez rapidement les produits qui leur sont associés.</p>
            </div>
            <?php if ($canManage): ?>
                <button type="button" class="<?= $ui['primary'] ?>" @click="$store.catalog.openBrand()">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z"/></svg>
                    Ajouter une marque
                </button>
            <?php endif; ?>
        </div>

        <nav class="inline-flex flex-wrap gap-1 rounded-2xl bg-slate-100 p-1 dark:bg-slate-900" aria-label="Gestion du catalogue">
            <a class="<?= $ui['navLink'] ?>" href="/dashboard/catalog">Produits</a>
            <a class="<?= $ui['navLink'] ?>" href="/dashboard/catalog/categories">Catégories</a>
            <a class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-teal-800 shadow-sm dark:bg-slate-800 dark:text-teal-300" href="/dashboard/catalog/brands" aria-current="page">Marques</a>
            <a class="<?= $ui['navLink'] ?>" href="/dashboard/inventory">Inventaire</a>
        </nav>
    </header>

    <?php if (($catalog_reference_success ?? '') !== ''): ?>
        <div role="status" class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/60 dark:text-emerald-200">
            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.86-9.64a.75.75 0 1 0-1.22-.87l-3.2 4.48-1.7-1.7a.75.75 0 0 0-1.06 1.06l2.3 2.3a.75.75 0 0 0 1.14-.09l3.74-5.18Z" clip-rule="evenodd"/></svg>
            <span><?= $escape($catalog_reference_success) ?></span>
        </div>
    <?php endif; ?>
    <?php if (($catalog_reference_error ?? '') !== ''): ?>
        <div role="alert" class="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900/60 dark:bg-red-950/60 dark:text-red-200">
            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-4.25a.75.75 0 0 1 .75.75v4a.75.75 0 0 1-1.5 0V6.5a.75.75 0 0 1 .75-.75Zm0 8.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
            <span><?= $escape($catalog_reference_error) ?></span>
        </div>
    <?php endif; ?>

    <x-container size="xxl" :reduiceable="false" class="<?= $ui['panel'] ?>" aria-labelledby="brands-title">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 p-5 dark:border-slate-800 sm:p-6">
            <div>
                <h2 id="brands-title" class="text-lg font-semibold text-slate-900 dark:text-white">Référentiel des marques</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="$store.catalog.brandTotal"></span> marque(s) active(s)
                </p>
            </div>
            <div class="relative w-full sm:w-72">
                <label class="sr-only" for="brand-search">Rechercher une marque</label>
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.45 4.39l3.58 3.58a.75.75 0 1 1-1.06 1.06l-3.58-3.58A7 7 0 0 1 2 9Z" clip-rule="evenodd"/></svg>
                <input id="brand-search" type="search" name="search" autocomplete="off" class="<?= $ui['field'] ?> pl-10" value="<?= $escape($catalog_reference_search ?? '') ?>" x-model="$store.catalog.filters.search" @input.debounce.300ms="$store.catalog.applyReferenceSearch('brands')" placeholder="Rechercher une marque">
            </div>
        </div>

        <x-table id="catalog-brands-table" variant="plain" store="catalog" store-rows-key="brandRows"
            store-total-key="brandTotal" store-loading-key="brandLoading" store-query-method="queryBrands"
            store-autoload :columns="$brandColumns" :page-size="10" :page-sizes="[10, 25, 50, 100]"
            empty-message="Aucune marque" no-results-message="Aucun résultat trouvé">
            <x-slot:cell-brand>
                <div class="flex min-w-0 items-center gap-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-sm font-bold uppercase text-white dark:bg-slate-100 dark:text-slate-900"
                        aria-hidden="true" x-text="(row.name || '?').charAt(0)"></span>
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900 dark:text-white" x-text="row.name"></p>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                            <span class="rounded-full px-2 py-0.5 font-medium ring-1 ring-inset"
                                :class="Number(row.is_trending) === 1 ? 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20' : 'bg-slate-50 text-slate-600 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-600'"
                                x-text="Number(row.is_trending) === 1 ? 'Tendance' : 'Non tendance'"></span>
                            <span x-text="`${Number(row.views_count || 0).toLocaleString('fr-FR')} visite(s)`"></span>
                        </p>
                    </div>
                </div>
            </x-slot:cell-brand>
            <x-slot:cell-actions>
                <x-action-menu>
                    <a role="menuitem" class="flex w-full items-center rounded px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                        :href="`/dashboard/catalog?brand_id=${row.id}`">Voir les produits</a>
                    <?php if ($has('inventory.discount.manage')): ?>
                        <button type="button" role="menuitem" class="flex w-full items-center rounded px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                            data-open-discount-modal data-discount-target-type="brand"
                            :data-discount-target-id="row.id" :data-discount-target-name="row.name">Configurer les réductions</button>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                        <button type="button" role="menuitem" class="flex w-full items-center rounded px-3 py-2 text-left text-sm text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-500/10"
                            @click="$store.catalog.toggleTrending('brand', row)"
                            x-text="Number(row.is_trending) === 1 ? 'Retirer des tendances' : 'Mettre en tendance'"></button>
                        <button type="button" role="menuitem" class="flex w-full items-center rounded px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                            @click="$store.catalog.openBrand(row)">Modifier</button>
                        <button type="button" role="menuitem" class="flex w-full items-center rounded px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                            @click="$store.catalog.deleteBrand(row)">Supprimer</button>
                    <?php endif; ?>
                </x-action-menu>
            </x-slot:cell-actions>
        </x-table>
    </x-container>

    <?php if ($has('inventory.discount.manage')): ?>
        <dialog data-discount-modal aria-labelledby="brand-discounts-title" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-2xl bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/60 dark:bg-slate-900 dark:text-slate-100">
            <div class="space-y-5 p-5 sm:p-6">
                <header class="flex items-start justify-between gap-4">
                    <div><h2 id="brand-discounts-title" class="text-lg font-semibold">Réductions de la marque</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" data-discount-target-name></p></div>
                    <button type="button" data-discount-close class="<?= $ui['secondary'] ?>" aria-label="Fermer">×</button>
                </header>
                <div class="space-y-3" data-discount-list aria-live="polite"></div>
                <button type="button" data-discount-new class="<?= $ui['primary'] ?>">Ajouter une réduction</button>
                <section data-discount-editor hidden class="border-t border-slate-200 pt-5 dark:border-slate-700">
                    <h3 data-discount-editor-title class="mb-4 font-semibold"></h3>
                    <form data-discount-form class="grid gap-4 sm:grid-cols-2">
                        <input type="hidden" name="discount_id">
                        <label class="<?= $ui['label'] ?>">Type<select name="discount_type" data-discount-type required class="<?= $ui['field'] ?>"><option value="percentage">Pourcentage</option><option value="fixed">Montant fixe</option></select></label>
                        <label class="<?= $ui['label'] ?>">Taux / montant<input name="value" type="number" min="0.01" step="0.01" required class="<?= $ui['field'] ?>" placeholder="15.00"></label>
                        <label data-discount-currency hidden class="<?= $ui['label'] ?>">Devise<input name="currency" maxlength="3" pattern="[A-Z]{3}" class="<?= $ui['field'] ?>" placeholder="MAD"></label>
                        <label class="<?= $ui['label'] ?>">Début (heure locale)<input name="starts_at" type="datetime-local" step="1" required class="<?= $ui['field'] ?>"></label>
                        <label class="<?= $ui['label'] ?>">Fin (heure locale)<input name="ends_at" type="datetime-local" step="1" required class="<?= $ui['field'] ?>"></label>
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-300 sm:col-span-2"><input name="is_active" type="checkbox" value="1" checked> Réduction activée</label>
                        <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" data-discount-cancel class="<?= $ui['secondary'] ?>">Annuler</button><button type="submit" class="<?= $ui['primary'] ?>">Enregistrer</button></div>
                    </form>
                </section>
            </div>
        </dialog>
    <?php endif; ?>

</main>